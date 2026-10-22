<?php
/**
 * Products > Variation Converter, in three tabs. Combine: the scan as a review list; each candidate can be edited
 * (name, attribute, parent SKU, which products, their labels) and then created, or marked "not a collection" so later
 * scans skip it. Split: variable products, each opening a card to make one simple product per variation. History:
 * everything converted, with Undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_PAGE = 'variation-converter';
const VARCONV_CAP = 'manage_woocommerce';
const VARCONV_PER_PAGE = 25;

add_action( 'admin_menu', 'varconv_menu', 60 );
function varconv_menu(): void {
	$hook = add_submenu_page(
		'edit.php?post_type=product',
		__( 'Variation Converter', 'variation-converter' ),
		__( 'Variation Converter', 'variation-converter' ),
		VARCONV_CAP,
		VARCONV_PAGE,
		'varconv_render'
	);
	if ( $hook ) {
		add_action( 'admin_print_styles-' . $hook, 'varconv_assets' );
	}
}

function varconv_assets(): void {
	$url = plugin_dir_url( VARCONV_FILE ) . 'assets/';
	wp_enqueue_style( 'varconv-admin', $url . 'admin.css', array(), VARCONV_VERSION );
	wp_enqueue_script( 'varconv-admin', $url . 'admin.js', array(), VARCONV_VERSION, true );
}

/** @return array<string,string> Tab key => label. */
function varconv_tabs(): array {
	return array(
		'combine' => __( 'Combine', 'variation-converter' ),
		'split'   => __( 'Split', 'variation-converter' ),
		'history' => __( 'History', 'variation-converter' ),
	);
}

/** An error to show after the redirect, kept for the current user rather than put in the URL. */
function varconv_fail( string $message, array $args ): void {
	set_transient( 'varconv_error_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	varconv_back( $args );
}

function varconv_url( array $args = array() ): string {
	return add_query_arg( array_merge( array( 'post_type' => 'product', 'page' => VARCONV_PAGE ), $args ), admin_url( 'edit.php' ) );
}

/** Back to the page, keeping the tab, search and page number the form came from. Callers have checked the nonce. */
function varconv_back( array $args ): void {
	$keep = array();
	foreach ( array( 'tab', 's', 'paged' ) as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( '' !== $value ) {
			$keep[ $key ] = $value;
		}
	}
	wp_safe_redirect( varconv_url( array_merge( $keep, $args ) ) );
	exit;
}

add_action( 'admin_post_varconv_create', 'varconv_handle_create' );
function varconv_handle_create(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
	check_admin_referer( 'varconv_create_' . $key );
	if ( ! empty( $_POST['dismiss'] ) ) {
		$dismissed = (array) get_option( VARCONV_DISMISSED_OPTION, array() );
		$dismissed[] = $key;
		update_option( VARCONV_DISMISSED_OPTION, array_values( array_unique( $dismissed ) ), false );
		delete_transient( VARCONV_SCAN_TRANSIENT );
		varconv_back( array( 'varconv_done' => 'dismissed' ) );
	}
	$members = array();
	$rows    = isset( $_POST['members'] ) && is_array( $_POST['members'] ) ? map_deep( wp_unslash( $_POST['members'] ), 'sanitize_text_field' ) : array();
	foreach ( $rows as $id => $row ) {
		if ( is_array( $row ) && ! empty( $row['use'] ) ) {
			$members[ absint( $id ) ] = (string) ( $row['label'] ?? '' );
		}
	}
	try {
		$id = varconv_create(
			isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			isset( $_POST['attribute'] ) ? sanitize_text_field( wp_unslash( $_POST['attribute'] ) ) : '',
			isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '',
			$members
		);
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array( 'varconv_key' => $key ) );
	}
	varconv_back( array( 'varconv_done' => 'created', 'varconv_product' => $id ) );
}

add_action( 'admin_post_varconv_undo', 'varconv_handle_undo' );
function varconv_handle_undo(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	$id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
	check_admin_referer( 'varconv_undo_' . $id );
	try {
		$restored = varconv_undo( $id );
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array() );
	}
	varconv_back( array( 'varconv_done' => 'undone', 'varconv_restored' => $restored ) );
}

add_action( 'admin_post_varconv_rescan', 'varconv_handle_rescan' );
function varconv_handle_rescan(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	check_admin_referer( 'varconv_rescan' );
	if ( ! empty( $_POST['undismiss'] ) ) {
		delete_option( VARCONV_DISMISSED_OPTION );
	}
	delete_transient( VARCONV_SCAN_TRANSIENT );
	varconv_back( array() );
}

add_action( 'admin_post_varconv_split', 'varconv_handle_split' );
function varconv_handle_split(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	$id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
	check_admin_referer( 'varconv_split_' . $id );
	$names = array();
	$rows  = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? map_deep( wp_unslash( $_POST['rows'] ), 'sanitize_text_field' ) : array();
	foreach ( $rows as $variation => $row ) {
		if ( is_array( $row ) && ! empty( $row['use'] ) ) {
			$names[ absint( $variation ) ] = (string) ( $row['name'] ?? '' );
		}
	}
	try {
		$created = varconv_split_product( $id, $names );
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array( 'product' => $id, 'varconv_failed' => 1 ) );
	}
	varconv_back( array( 'varconv_done' => 'split', 'varconv_product' => $id, 'varconv_count' => count( $created ) ) );
}

add_action( 'admin_post_varconv_split_undo', 'varconv_handle_split_undo' );
function varconv_handle_split_undo(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	$id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
	check_admin_referer( 'varconv_split_undo_' . $id );
	try {
		$deleted = varconv_split_undo( $id );
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array() );
	}
	varconv_back( array( 'varconv_done' => 'split_undone', 'varconv_product' => $id, 'varconv_count' => $deleted ) );
}

/** "Split into simple products" under each variable product in Products > All Products. */
add_filter( 'post_row_actions', 'varconv_row_action', 10, 2 );
function varconv_row_action( array $actions, WP_Post $post ): array {
	if ( 'product' !== $post->post_type || 'publish' !== $post->post_status || ! current_user_can( VARCONV_CAP ) ) {
		return $actions;
	}
	$product = wc_get_product( $post->ID );
	if ( $product && $product->is_type( 'variable' ) && '' === (string) $product->get_meta( VARCONV_SPLIT_META ) ) {
		$actions['varconv_split'] = sprintf( '<a href="%s">%s</a>', esc_url( varconv_url( array( 'tab' => 'split', 'product' => $post->ID ) ) ), esc_html__( 'Split into simple products', 'variation-converter' ) );
	}
	return $actions;
}

// The GET values read on this screen (tab, search, page, product, which message to show) only choose what is
// displayed; every change goes through an admin-post handler that checks its nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

function varconv_tab(): string {
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	return isset( varconv_tabs()[ $tab ] ) ? $tab : 'combine';
}

function varconv_render(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	$tab = varconv_tab();
	?>
	<div class="wrap varconv">
		<h1><?php esc_html_e( 'Variation Converter', 'variation-converter' ); ?></h1>
		<nav class="nav-tab-wrapper">
			<?php foreach ( varconv_tabs() as $key => $label ) : ?>
				<a href="<?php echo esc_url( varconv_url( 'combine' === $key ? array() : array( 'tab' => $key ) ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		varconv_notice();
		if ( 'split' === $tab ) {
			varconv_render_split();
		} elseif ( 'history' === $tab ) {
			varconv_render_history();
		} else {
			varconv_render_combine();
		}
		?>
	</div>
	<?php
}

function varconv_render_combine(): void {
	$scan = varconv_candidates();
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$collections = $scan['collections'];
	if ( '' !== $search ) {
		$collections = array_values( array_filter( $collections, static function ( $c ) use ( $search ) {
			$hay = $c['name'] . ' ' . $c['sku'] . ' ' . implode( ' ', array_column( $c['members'], 'sku' ) ) . ' ' . implode( ' ', array_column( $c['members'], 'name' ) );
			return false !== stripos( $hay, $search );
		} ) );
	}
	$total = count( $collections );
	$pages = max( 1, (int) ceil( $total / VARCONV_PER_PAGE ) );
	$paged = min( $pages, max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ) );
	$shown = array_slice( $collections, ( $paged - 1 ) * VARCONV_PER_PAGE, VARCONV_PER_PAGE );
	$products = array_sum( array_map( static fn( $c ) => count( $c['members'] ), $scan['collections'] ) );
	$dismissed = count( (array) get_option( VARCONV_DISMISSED_OPTION, array() ) );
	?>
		<p><?php esc_html_e( 'Groups of simple products whose names differ only by a variant (color, size, flavor...) and whose SKUs share a family. Check each one, fix the name, attribute and labels if needed, and create it: that makes one variable product with a variation per checked product (same SKU, prices, stock and image), and hides those simple products from the catalog and search. They are not deleted, and Undo in History brings them back.', 'variation-converter' ); ?></p>

		<form method="get" class="varconv-toolbar">
			<input type="hidden" name="post_type" value="product">
			<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name or SKU', 'variation-converter' ); ?>">
			<button class="button"><?php esc_html_e( 'Search', 'variation-converter' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-toolbar">
			<input type="hidden" name="action" value="varconv_rescan">
			<?php wp_nonce_field( 'varconv_rescan' ); ?>
			<button class="button"><?php esc_html_e( 'Scan again', 'variation-converter' ); ?></button>
			<?php if ( $dismissed ) : ?>
				<button class="button-link" name="undismiss" value="1">
					<?php
					/* translators: %d: number of dismissed groups. */
					echo esc_html( sprintf( _n( 'Show %d dismissed again', 'Show %d dismissed again', $dismissed, 'variation-converter' ), $dismissed ) );
					?>
				</button>
			<?php endif; ?>
		</form>
		<p class="description">
			<?php
			echo esc_html( sprintf(
				/* translators: 1: number of groups, 2: number of products in them, 3: time since the scan, e.g. "5 mins". */
				__( '%1$d possible collections (%2$d products), scanned %3$s ago.', 'variation-converter' ),
				count( $scan['collections'] ),
				$products,
				human_time_diff( $scan['time'] )
			) );
			if ( '' !== $search ) {
				/* translators: 1: number of matching groups, 2: search text. */
				echo ' ' . esc_html( sprintf( __( '%1$d match "%2$s".', 'variation-converter' ), $total, $search ) );
			}
			?>
		</p>

		<datalist id="varconv-attributes">
			<?php foreach ( array( 'Color', 'Size', 'Flavor', 'Scent', 'Volume', 'Style' ) as $option ) : ?>
				<option><?php echo esc_html( $option ); ?></option>
			<?php endforeach; ?>
		</datalist>
		<?php foreach ( $shown as $c ) : ?>
			<?php varconv_render_candidate( $c, $search, $paged ); ?>
		<?php endforeach; ?>
		<?php if ( ! $shown ) : ?>
			<p><?php esc_html_e( 'No collections to review.', 'variation-converter' ); ?></p>
		<?php endif; ?>

		<?php if ( $pages > 1 ) : ?>
			<p class="varconv-pages">
				<?php
				echo wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%', varconv_url( '' !== $search ? array( 's' => $search ) : array() ) ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				) ) );
				?>
			</p>
		<?php endif; ?>
	<?php
}

/** The Split tab: a card for one variable product, or the list to pick one from. */
function varconv_render_split(): void {
	$id = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0;
	$product = $id ? wc_get_product( $id ) : null;
	if ( $product instanceof WC_Product_Variable ) {
		echo '<p><a href="' . esc_url( varconv_url( array( 'tab' => 'split' ) ) ) . '">&larr; ' . esc_html__( 'All variable products', 'variation-converter' ) . '</a></p>';
		varconv_render_split_card( $product );
		return;
	}
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
	$found = varconv_variables( $search, $paged, VARCONV_PER_PAGE );
	$pages = max( 1, (int) ceil( $found['total'] / VARCONV_PER_PAGE ) );
	?>
	<p><?php esc_html_e( 'Pick a variable product to make one simple product from each of its variations (same SKU, prices, stock and image). The variable product is hidden from the catalog and search, not deleted, and Undo in History brings it back.', 'variation-converter' ); ?></p>
	<form method="get" class="varconv-toolbar">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<input type="hidden" name="tab" value="split">
		<label class="screen-reader-text" for="varconv-split-search"><?php esc_html_e( 'Search variable products', 'variation-converter' ); ?></label>
		<input type="search" id="varconv-split-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name or SKU', 'variation-converter' ); ?>">
		<button class="button"><?php esc_html_e( 'Search', 'variation-converter' ); ?></button>
	</form>
	<?php if ( ! $found['ids'] ) : ?>
		<p>
			<?php
			if ( '' !== $search ) {
				/* translators: %s: search text. */
				echo esc_html( sprintf( __( 'No variable products match "%s".', 'variation-converter' ), $search ) );
			} else {
				esc_html_e( 'There are no published variable products to split.', 'variation-converter' );
			}
			?>
		</p>
		<?php return; ?>
	<?php endif; ?>
	<table class="widefat striped varconv-list">
		<thead><tr>
			<th><?php esc_html_e( 'Variable product', 'variation-converter' ); ?></th>
			<th><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
			<th><?php esc_html_e( 'Variations', 'variation-converter' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'variation-converter' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $found['ids'] as $pid ) : ?>
			<?php $p = wc_get_product( $pid ); ?>
			<tr>
				<td><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $pid, 'raw' ) ); ?></a></td>
				<td><?php echo esc_html( $p ? $p->get_sku( 'edit' ) : '' ); ?></td>
				<td><?php echo (int) ( $p ? count( $p->get_children() ) : 0 ); ?></td>
				<td><a class="button" href="<?php echo esc_url( varconv_url( array( 'tab' => 'split', 'product' => $pid ) ) ); ?>"><?php echo $p && '' !== (string) $p->get_meta( VARCONV_META ) ? esc_html__( 'Undo...', 'variation-converter' ) : esc_html__( 'Split...', 'variation-converter' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $pages > 1 ) : ?>
		<p class="varconv-pages">
			<?php
			echo wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%', varconv_url( array_filter( array( 'tab' => 'split', 's' => $search ) ) ) ),
				'format'  => '',
				'current' => min( $paged, $pages ),
				'total'   => $pages,
			) ) );
			?>
		</p>
	<?php endif; ?>
	<?php
}

function varconv_render_split_card( WC_Product_Variable $product ): void {
	$id = $product->get_id();
	$error = isset( $_GET['varconv_failed'] );
	?>
	<div class="varconv-candidate<?php echo $error ? ' is-error' : ''; ?>" id="varconv-split-<?php echo (int) $id; ?>">
		<h2><?php echo esc_html( get_post_field( 'post_title', $id, 'raw' ) ); ?></h2>
		<?php if ( '' !== (string) $product->get_meta( VARCONV_META ) ) : ?>
			<p><?php esc_html_e( 'This was combined by Variation Converter. Undo restores the original products.', 'variation-converter' ); ?></p>
			<?php varconv_undo_form( 'varconv_undo', $id ); ?>
		<?php elseif ( '' !== (string) $product->get_meta( VARCONV_SPLIT_META ) ) : ?>
			<p><?php esc_html_e( 'This product is already split. Undo deletes the simple products made from it and shows it again.', 'variation-converter' ); ?></p>
			<?php varconv_undo_form( 'varconv_split_undo', $id ); ?>
		<?php elseif ( 'publish' !== $product->get_status() ) : ?>
			<p><?php esc_html_e( 'Only published products can be split.', 'variation-converter' ); ?></p>
		<?php else : ?>
			<?php $rows = varconv_split_rows( $product ); ?>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'This product has no variations to split.', 'variation-converter' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="varconv_split">
					<input type="hidden" name="tab" value="split">
					<input type="hidden" name="product" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( 'varconv_split_' . $id ); ?>
					<table class="widefat striped">
						<thead><tr>
							<th class="check"><span class="screen-reader-text"><?php esc_html_e( 'Include', 'variation-converter' ); ?></span></th>
							<th class="thumb"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'variation-converter' ); ?></span></th>
							<th><?php esc_html_e( 'New product name', 'variation-converter' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
							<th><?php esc_html_e( 'Price', 'variation-converter' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'variation-converter' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><input type="checkbox" name="rows[<?php echo (int) $r['id']; ?>][use]" value="1" <?php checked( '' === $r['problem'] ); ?> <?php disabled( '' !== $r['problem'] ); ?> aria-label="<?php /* translators: %s: new product name. */ echo esc_attr( sprintf( __( 'Include %s', 'variation-converter' ), $r['name'] ) ); ?>"></td>
								<td class="thumb"><?php echo $r['image'] ? wp_get_attachment_image( $r['image'], array( 40, 40 ) ) : ''; ?></td>
								<td>
									<input type="text" name="rows[<?php echo (int) $r['id']; ?>][name]" value="<?php echo esc_attr( $r['name'] ); ?>" aria-label="<?php /* translators: %d: variation ID. */ echo esc_attr( sprintf( __( 'New product name for variation %d', 'variation-converter' ), $r['id'] ) ); ?>">
									<?php if ( $r['problem'] ) : ?>
										<p class="varconv-notes"><?php echo esc_html( $r['problem'] ); ?></p>
									<?php endif; ?>
								</td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $r['sku'] ?: '#' . $r['id'] ); ?></a></td>
								<td><?php echo esc_html( (string) $r['price'] ); ?></td>
								<td><?php echo esc_html( (string) $r['stock'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Each new product takes this product\'s descriptions, categories, tags and other attributes. Unchecked variations are not made into products and stay only in the hidden variable product.', 'variation-converter' ); ?></p>
					<div class="varconv-actions">
						<button class="button button-primary">
							<?php
							$count = count( array_filter( $rows, static fn( $r ) => '' === $r['problem'] ) );
							/* translators: %d: number of simple products to make. */
							echo esc_html( sprintf( _n( 'Split into %d simple product', 'Split into %d simple products', $count, 'variation-converter' ), $count ) );
							?>
						</button>
						<span class="description"><?php esc_html_e( 'The variable product is hidden, not deleted; Undo in History brings it back.', 'variation-converter' ); ?></span>
					</div>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/** An Undo button for a conversion, posting to the given admin-post action; assets/admin.js asks first. */
function varconv_undo_form( string $action, int $id ): void {
	$confirm = 'varconv_undo' === $action
		? __( 'Delete this variable product and show its simple products again?', 'variation-converter' )
		: __( 'Delete the simple products made from this product and show it again?', 'variation-converter' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-undo" data-confirm="<?php echo esc_attr( $confirm ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<input type="hidden" name="tab" value="history">
		<input type="hidden" name="product" value="<?php echo (int) $id; ?>">
		<?php wp_nonce_field( $action . '_' . $id ); ?>
		<button class="button button-link-delete"><?php esc_html_e( 'Undo', 'variation-converter' ); ?></button>
	</form>
	<?php
}

/** Links to products by SKU, or by ID when they have none. */
function varconv_product_links( array $ids ): string {
	return implode( ', ', array_map( static fn( $s ) => sprintf(
		'<a href="%s">%s</a>',
		esc_url( (string) get_edit_post_link( (int) $s ) ),
		esc_html( (string) get_post_meta( (int) $s, '_sku', true ) ?: '#' . (int) $s )
	), $ids ) );
}

/** The History tab: every product combined or split here, with Undo. */
function varconv_render_history(): void {
	$created = varconv_created();
	$split = varconv_split_list();
	?>
	<h2><?php esc_html_e( 'Combined', 'variation-converter' ); ?></h2>
	<?php if ( ! $created ) : ?>
		<p><?php esc_html_e( 'Nothing combined yet.', 'variation-converter' ); ?></p>
	<?php else : ?>
		<table class="widefat striped varconv-list">
			<thead><tr>
				<th><?php esc_html_e( 'Variable product', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Variations', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Made from', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'variation-converter' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $created as $id ) : ?>
				<?php
				$product = wc_get_product( $id );
				$sources = json_decode( (string) get_post_meta( $id, VARCONV_META, true ), true ) ?: array();
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $id, 'raw' ) ); ?></a>
						&middot; <a href="<?php echo esc_url( (string) get_permalink( $id ) ); ?>"><?php esc_html_e( 'View', 'variation-converter' ); ?></a></td>
					<td><?php echo esc_html( $product ? $product->get_sku( 'edit' ) : '' ); ?></td>
					<td><?php echo (int) ( $product ? count( $product->get_children() ) : 0 ); ?></td>
					<td><?php echo wp_kses_post( varconv_product_links( $sources ) ); ?></td>
					<td><?php varconv_undo_form( 'varconv_undo', $id ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Split', 'variation-converter' ); ?></h2>
	<?php if ( ! $split ) : ?>
		<p><?php esc_html_e( 'Nothing split yet.', 'variation-converter' ); ?></p>
	<?php else : ?>
		<table class="widefat striped varconv-list">
			<thead><tr>
				<th><?php esc_html_e( 'Variable product (hidden)', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Simple products made', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'variation-converter' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $split as $id ) : ?>
				<?php
				$product = wc_get_product( $id );
				$made = json_decode( (string) get_post_meta( $id, VARCONV_SPLIT_META, true ), true ) ?: array();
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $id, 'raw' ) ); ?></a></td>
					<td><?php echo esc_html( $product ? $product->get_sku( 'edit' ) : '' ); ?></td>
					<td><?php echo wp_kses_post( varconv_product_links( $made ) ); ?></td>
					<td><?php varconv_undo_form( 'varconv_split_undo', $id ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

function varconv_notice(): void {
	$error = get_transient( 'varconv_error_' . get_current_user_id() );
	if ( false !== $error ) {
		delete_transient( 'varconv_error_' . get_current_user_id() );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( (string) $error ) );
		return;
	}
	$done = isset( $_GET['varconv_done'] ) ? sanitize_key( wp_unslash( $_GET['varconv_done'] ) ) : '';
	switch ( $done ) {
		case 'created':
			$id = isset( $_GET['varconv_product'] ) ? absint( wp_unslash( $_GET['varconv_product'] ) ) : 0;
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				wp_kses_post( sprintf(
					/* translators: 1: edit link, 2: product name, 3: view link. */
					__( 'Created <a href="%1$s">%2$s</a> (<a href="%3$s">view</a>). Its simple products are now hidden.', 'variation-converter' ),
					esc_url( (string) get_edit_post_link( $id ) ),
					esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
					esc_url( (string) get_permalink( $id ) )
				) )
			);
			break;
		case 'undone':
			$restored = isset( $_GET['varconv_restored'] ) ? absint( wp_unslash( $_GET['varconv_restored'] ) ) : 0;
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of simple products shown again. */
					_n( 'Deleted the variable product; %d simple product is visible again.', 'Deleted the variable product; %d simple products are visible again.', $restored, 'variation-converter' ),
					$restored
				) )
			);
			break;
		case 'split':
			$id    = isset( $_GET['varconv_product'] ) ? absint( wp_unslash( $_GET['varconv_product'] ) ) : 0;
			$count = isset( $_GET['varconv_count'] ) ? absint( wp_unslash( $_GET['varconv_count'] ) ) : 0;
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				wp_kses_post( sprintf(
					/* translators: 1: product name, 2: number of simple products, 3: link to the History tab. */
					_n( 'Split %1$s into %2$d simple product. The variable product is now hidden; Undo is in <a href="%3$s">History</a>.', 'Split %1$s into %2$d simple products. The variable product is now hidden; Undo is in <a href="%3$s">History</a>.', $count, 'variation-converter' ),
					esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
					$count,
					esc_url( varconv_url( array( 'tab' => 'history' ) ) )
				) )
			);
			break;
		case 'split_undone':
			$id    = isset( $_GET['varconv_product'] ) ? absint( wp_unslash( $_GET['varconv_product'] ) ) : 0;
			$count = isset( $_GET['varconv_count'] ) ? absint( wp_unslash( $_GET['varconv_count'] ) ) : 0;
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: number of simple products deleted, 2: product name. */
					_n( 'Deleted %1$d simple product; %2$s is visible again.', 'Deleted %1$d simple products; %2$s is visible again.', $count, 'variation-converter' ),
					$count,
					get_post_field( 'post_title', $id, 'raw' )
				) )
			);
			break;
		case 'dismissed':
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html__( 'Marked as not a collection; scans will skip it.', 'variation-converter' ) );
			break;
	}
}

function varconv_render_candidate( array $c, string $search, int $paged ): void {
	$error = isset( $_GET['varconv_key'] ) && sanitize_key( wp_unslash( $_GET['varconv_key'] ) ) === $c['key'];
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-candidate<?php echo $error ? ' is-error' : ''; ?>" id="varconv-<?php echo esc_attr( $c['key'] ); ?>">
		<input type="hidden" name="action" value="varconv_create">
		<input type="hidden" name="key" value="<?php echo esc_attr( $c['key'] ); ?>">
		<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
		<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
		<?php wp_nonce_field( 'varconv_create_' . $c['key'] ); ?>
		<div class="varconv-fields">
			<label class="name"><?php esc_html_e( 'Product name', 'variation-converter' ); ?> <input type="text" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" required></label>
			<label><?php esc_html_e( 'Attribute', 'variation-converter' ); ?> <input type="text" name="attribute" value="<?php echo esc_attr( $c['attribute'] ); ?>" list="varconv-attributes" required></label>
			<label><?php esc_html_e( 'Parent SKU', 'variation-converter' ); ?> <input type="text" name="sku" value="<?php echo esc_attr( $c['sku'] ); ?>"></label>
		</div>
		<table class="widefat striped">
			<thead><tr>
				<th class="check"></th>
				<th class="label"><?php esc_html_e( 'Variant label', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Current title', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Price', 'variation-converter' ); ?></th>
				<th><?php esc_html_e( 'Stock', 'variation-converter' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $c['members'] as $m ) : ?>
				<tr>
					<?php /* translators: %s: product SKU. */ ?>
					<td><input type="checkbox" name="members[<?php echo (int) $m['id']; ?>][use]" value="1" checked aria-label="<?php echo esc_attr( sprintf( __( 'Include %s', 'variation-converter' ), $m['sku'] ) ); ?>"></td>
					<td><input type="text" name="members[<?php echo (int) $m['id']; ?>][label]" value="<?php echo esc_attr( $m['label'] ); ?>"></td>
					<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $m['id'] ) ); ?>"><?php echo esc_html( $m['sku'] ); ?></a></td>
					<td class="title"><?php echo esc_html( varconv_plain( (string) ( $m['title'] ?? $m['name'] ) ) ); ?></td>
					<td><?php echo esc_html( (string) ( $m['price'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $m['stock'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $c['notes'] ) : ?>
			<p class="varconv-notes"><?php echo esc_html( implode( ' ', $c['notes'] ) ); ?></p>
		<?php endif; ?>
		<div class="varconv-actions">
			<button class="button button-primary"><?php esc_html_e( 'Create variable product', 'variation-converter' ); ?></button>
			<button class="button" name="dismiss" value="1" formnovalidate><?php esc_html_e( 'Not a collection', 'variation-converter' ); ?></button>
		</div>
	</form>
	<?php
}
