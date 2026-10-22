<?php
/**
 * Products > Variation Converter: the scan as a review list. Each candidate can be edited (name, attribute, parent
 * SKU, which products, their labels) and then created, or marked "not a collection" so later scans skip it.
 */

const VARCONV_PAGE = 'variation-converter';
const VARCONV_CAP = 'manage_woocommerce';
const VARCONV_PER_PAGE = 25;

add_action( 'admin_menu', 'varconv_menu', 60 );
function varconv_menu(): void {
	add_submenu_page( 'edit.php?post_type=product', 'Variation Converter', 'Variation Converter', VARCONV_CAP, VARCONV_PAGE, 'varconv_render' );
}

function varconv_url( array $args = array() ): string {
	return add_query_arg( array_merge( array( 'post_type' => 'product', 'page' => VARCONV_PAGE ), $args ), admin_url( 'edit.php' ) );
}

/** Back to the page, keeping the search and page number the form came from. */
function varconv_back( array $args ): void {
	$keep = array();
	foreach ( array( 's', 'paged' ) as $key ) {
		if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
			$keep[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
	}
	wp_safe_redirect( varconv_url( array_merge( $keep, $args ) ) );
	exit;
}

add_action( 'admin_post_varconv_create', 'varconv_handle_create' );
function varconv_handle_create(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	$key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
	check_admin_referer( 'varconv_create_' . $key );
	if ( ! empty( $_POST['dismiss'] ) ) {
		$dismissed = (array) get_option( VARCONV_DISMISSED_OPTION, array() );
		$dismissed[] = $key;
		update_option( VARCONV_DISMISSED_OPTION, array_values( array_unique( $dismissed ) ), false );
		delete_transient( VARCONV_SCAN_TRANSIENT );
		varconv_back( array( 'varconv_done' => 'dismissed' ) );
	}
	$members = array();
	foreach ( (array) ( $_POST['members'] ?? array() ) as $id => $row ) {
		if ( ! empty( $row['use'] ) ) {
			$members[ (int) $id ] = sanitize_text_field( wp_unslash( $row['label'] ?? '' ) );
		}
	}
	try {
		$id = varconv_create(
			sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['attribute'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) ),
			$members
		);
	} catch ( RuntimeException $e ) {
		varconv_back( array( 'varconv_error' => rawurlencode( $e->getMessage() ), 'varconv_key' => $key ) );
	}
	varconv_back( array( 'varconv_done' => 'created', 'varconv_product' => $id ) );
}

add_action( 'admin_post_varconv_undo', 'varconv_handle_undo' );
function varconv_handle_undo(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	$id = (int) ( $_POST['product'] ?? 0 );
	check_admin_referer( 'varconv_undo_' . $id );
	try {
		$restored = varconv_undo( $id );
	} catch ( RuntimeException $e ) {
		varconv_back( array( 'varconv_error' => rawurlencode( $e->getMessage() ) ) );
	}
	varconv_back( array( 'varconv_done' => 'undone', 'varconv_restored' => $restored ) );
}

add_action( 'admin_post_varconv_rescan', 'varconv_handle_rescan' );
function varconv_handle_rescan(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'varconv_rescan' );
	if ( ! empty( $_POST['undismiss'] ) ) {
		delete_option( VARCONV_DISMISSED_OPTION );
	}
	delete_transient( VARCONV_SCAN_TRANSIENT );
	varconv_back( array() );
}

function varconv_render(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	$scan = varconv_candidates();
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$collections = $scan['collections'];
	if ( '' !== $search ) {
		$collections = array_values( array_filter( $collections, static function ( $c ) use ( $search ) {
			$hay = $c['name'] . ' ' . $c['sku'] . ' ' . implode( ' ', array_column( $c['members'], 'sku' ) ) . ' ' . implode( ' ', array_column( $c['members'], 'name' ) );
			return false !== stripos( $hay, $search );
		} ) );
	}
	$total = count( $collections );
	$pages = max( 1, (int) ceil( $total / VARCONV_PER_PAGE ) );
	$paged = min( $pages, max( 1, (int) ( $_GET['paged'] ?? 1 ) ) );
	$shown = array_slice( $collections, ( $paged - 1 ) * VARCONV_PER_PAGE, VARCONV_PER_PAGE );
	$products = array_sum( array_map( static fn( $c ) => count( $c['members'] ), $scan['collections'] ) );
	$dismissed = count( (array) get_option( VARCONV_DISMISSED_OPTION, array() ) );
	$created = varconv_created();
	?>
	<div class="wrap varconv">
		<h1>Variation Converter</h1>
		<p>Groups of simple products whose names differ only by a variant (color, size, flavor...) and whose SKUs share a family. Check each one, fix the name, attribute and labels if needed, and create it: that makes one variable product with a variation per checked product (same SKU, prices, stock and image), and hides those simple products from the catalog and search. They are not deleted, and Undo below brings them back.</p>
		<p><strong>Dev only.</strong> Production keeps simple products until the planned migration; this page does not exist there.</p>
		<?php varconv_notice(); ?>

		<form method="get" class="varconv-toolbar">
			<input type="hidden" name="post_type" value="product">
			<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Name or SKU">
			<button class="button">Search</button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-toolbar">
			<input type="hidden" name="action" value="varconv_rescan">
			<?php wp_nonce_field( 'varconv_rescan' ); ?>
			<button class="button">Scan again</button>
			<?php if ( $dismissed ) : ?>
				<button class="button-link" name="undismiss" value="1"><?php echo esc_html( sprintf( 'Show %d dismissed again', $dismissed ) ); ?></button>
			<?php endif; ?>
		</form>
		<p class="description">
			<?php
			echo esc_html( sprintf(
				'%1$d possible collections (%2$d products), scanned %3$s ago.%4$s',
				count( $scan['collections'] ),
				$products,
				human_time_diff( $scan['time'] ),
				'' !== $search ? sprintf( ' %d match "%s".', $total, $search ) : ''
			) );
			?>
		</p>

		<datalist id="varconv-attributes"><option>Color</option><option>Size</option><option>Flavor</option><option>Scent</option><option>Volume</option><option>Style</option></datalist>
		<?php foreach ( $shown as $c ) : ?>
			<?php varconv_render_candidate( $c, $search, $paged ); ?>
		<?php endforeach; ?>
		<?php if ( ! $shown ) : ?>
			<p>No collections to review.</p>
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

		<h2>Created</h2>
		<?php if ( ! $created ) : ?>
			<p>None yet.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Variable product</th><th>SKU</th><th>Variations</th><th>Made from</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $created as $id ) : ?>
					<?php
					$product = wc_get_product( $id );
					$sources = json_decode( (string) get_post_meta( $id, VARCONV_META, true ), true ) ?: array();
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $id, 'raw' ) ); ?></a>
							&middot; <a href="<?php echo esc_url( get_permalink( $id ) ); ?>">View</a></td>
						<td><?php echo esc_html( $product ? $product->get_sku( 'edit' ) : '' ); ?></td>
						<td><?php echo (int) ( $product ? count( $product->get_children() ) : 0 ); ?></td>
						<td>
							<?php
							echo wp_kses_post( implode( ', ', array_map( static fn( $s ) => sprintf(
								'<a href="%s">%s</a>',
								esc_url( get_edit_post_link( (int) $s ) ),
								esc_html( (string) get_post_meta( (int) $s, '_sku', true ) ?: '#' . (int) $s )
							), $sources ) ) );
							?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete this variable product and show its simple products again?');">
								<input type="hidden" name="action" value="varconv_undo">
								<input type="hidden" name="product" value="<?php echo (int) $id; ?>">
								<?php wp_nonce_field( 'varconv_undo_' . $id ); ?>
								<button class="button button-link-delete">Undo</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<style>
		.varconv-toolbar { display: inline-flex; gap: 6px; align-items: center; margin: 0 12px 8px 0; }
		.varconv-candidate { background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; margin: 12px 0; max-width: 1100px; }
		.varconv-candidate.is-error { border-color: #d63638; }
		.varconv-fields { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 8px; }
		.varconv-fields label { display: flex; flex-direction: column; font-weight: 600; gap: 2px; }
		.varconv-fields .name input { width: 360px; max-width: 100%; }
		.varconv-candidate table input[type=text] { width: 100%; }
		.varconv-candidate td.title { color: #50575e; }
		.varconv-notes { color: #996800; margin: 6px 0; }
		.varconv-actions { display: flex; gap: 8px; margin-top: 8px; }
	</style>
	<?php
}

function varconv_notice(): void {
	if ( isset( $_GET['varconv_error'] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( wp_unslash( $_GET['varconv_error'] ) ) );
		return;
	}
	switch ( $_GET['varconv_done'] ?? '' ) {
		case 'created':
			$id = (int) ( $_GET['varconv_product'] ?? 0 );
			printf(
				'<div class="notice notice-success"><p>Created <a href="%s">%s</a> (<a href="%s">view</a>). Its simple products are now hidden.</p></div>',
				esc_url( get_edit_post_link( $id ) ),
				esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
				esc_url( get_permalink( $id ) )
			);
			break;
		case 'undone':
			printf( '<div class="notice notice-success"><p>Deleted the variable product; %d simple products are visible again.</p></div>', (int) ( $_GET['varconv_restored'] ?? 0 ) );
			break;
		case 'dismissed':
			echo '<div class="notice notice-info"><p>Marked as not a collection; scans will skip it.</p></div>';
			break;
	}
}

function varconv_render_candidate( array $c, string $search, int $paged ): void {
	$error = ( $_GET['varconv_key'] ?? '' ) === $c['key'];
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-candidate<?php echo $error ? ' is-error' : ''; ?>" id="varconv-<?php echo esc_attr( $c['key'] ); ?>">
		<input type="hidden" name="action" value="varconv_create">
		<input type="hidden" name="key" value="<?php echo esc_attr( $c['key'] ); ?>">
		<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
		<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
		<?php wp_nonce_field( 'varconv_create_' . $c['key'] ); ?>
		<div class="varconv-fields">
			<label class="name">Product name <input type="text" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" required></label>
			<label>Attribute <input type="text" name="attribute" value="<?php echo esc_attr( $c['attribute'] ); ?>" list="varconv-attributes" required></label>
			<label>Parent SKU <input type="text" name="sku" value="<?php echo esc_attr( $c['sku'] ); ?>"></label>
		</div>
		<table class="widefat striped">
			<thead><tr><th style="width:2em"></th><th style="width:18%">Variant label</th><th>SKU</th><th>Current title</th><th>Price</th><th>Stock</th></tr></thead>
			<tbody>
			<?php foreach ( $c['members'] as $m ) : ?>
				<tr>
					<td><input type="checkbox" name="members[<?php echo (int) $m['id']; ?>][use]" value="1" checked aria-label="Include <?php echo esc_attr( $m['sku'] ); ?>"></td>
					<td><input type="text" name="members[<?php echo (int) $m['id']; ?>][label]" value="<?php echo esc_attr( $m['label'] ); ?>"></td>
					<td><a href="<?php echo esc_url( get_edit_post_link( (int) $m['id'] ) ); ?>"><?php echo esc_html( $m['sku'] ); ?></a></td>
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
			<button class="button button-primary">Create variable product</button>
			<button class="button" name="dismiss" value="1" formnovalidate>Not a collection</button>
		</div>
	</form>
	<?php
}
