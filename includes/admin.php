<?php
/**
 * Products > Variation Converter, in three tabs. Combine (admin-combine.php): the scan's groups, each opening into a
 * card to edit (name, attribute, parent SKU, categories, which products, their labels and order) and create, or skip
 * so later scans leave it out. Split: variable products, each opening a card to make one simple product per variation. History:
 * everything converted, with Undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_PAGE = 'variation-converter';
const VARCONV_CAP = 'manage_woocommerce';
const VARCONV_PER_PAGE = 25;

require_once __DIR__ . '/admin-combine.php';
require_once __DIR__ . '/ajax.php';
require_once __DIR__ . '/admin-products.php';
require_once __DIR__ . '/admin-split.php';

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
		add_action( 'load-' . $hook, 'varconv_screen_options' );
	}
}

function varconv_assets(): void {
	$url = plugin_dir_url( VARCONV_FILE ) . 'assets/';
	wp_enqueue_style( 'varconv-admin', $url . 'admin.css', array(), VARCONV_VERSION );
	wp_enqueue_script( 'varconv-admin', $url . 'admin.js', array(), VARCONV_VERSION, true );
	wp_localize_script( 'varconv-admin', 'varconv', array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'varconv_ajax' ),
		'price'   => varconv_price_settings(),
		'strings' => array(
			/* translators: 1: products checked, 2: products in the group. */
			'count'     => __( '%1$d of %2$d products', 'variation-converter' ),
			'working'   => __( 'Working...', 'variation-converter' ),
			'failed'    => __( 'Something went wrong. Reload the page and try again.', 'variation-converter' ),
			/* translators: %d: number of products left in the group. */
			'remaining' => __( 'The other %d products stay here as a group; check the ones to create next.', 'variation-converter' ),
			'skipped'   => __( 'Skipped. Later scans leave this group out.', 'variation-converter' ),
			'undone'    => __( 'Undone. The simple products are visible again; scan again to see them as a group.', 'variation-converter' ),
			/* translators: 1: groups done, 2: groups selected. */
			'progress'  => __( 'Creating groups: %1$d of %2$d done...', 'variation-converter' ),
			/* translators: 1: products made, 2: groups skipped, 3: groups that failed. */
			'bulkDone'  => __( 'Done: %1$d variable products created, %2$d groups skipped, %3$d failed. Reloading...', 'variation-converter' ),
			'onlyBand'  => __( 'Only these', 'variation-converter' ),
			/* translators: %s: SKU prefix shared by a band of rows. */
			'onlyLabel' => __( 'Check only the products whose SKU starts with %s', 'variation-converter' ),
		),
	) );
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

/**
 * Back to the page, keeping the tab, search, filters and page number the form came from, and scrolled to $anchor.
 * Callers have checked the nonce.
 */
function varconv_back( array $args, string $anchor = '' ): void {
	$keep = array();
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	foreach ( array( 'tab', 's', 'paged' ) as $key ) {
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( '' !== $value ) {
			$keep[ $key ] = $value;
		}
	}
	if ( isset( $_POST['filters'] ) ) {
		wp_parse_str( sanitize_text_field( wp_unslash( $_POST['filters'] ) ), $filters );
		$keep = array_merge( $keep, varconv_filter_args( varconv_filters( $filters ) ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	wp_safe_redirect( varconv_url( array_merge( $keep, $args ) ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
	exit;
}

/** Marks a group as not variants, so scans leave it out. */
function varconv_dismiss( string $key ): void {
	$dismissed = (array) get_option( VARCONV_DISMISSED_OPTION, array() );
	$dismissed[] = $key;
	update_option( VARCONV_DISMISSED_OPTION, array_values( array_unique( $dismissed ) ), false );
	delete_transient( VARCONV_SCAN_TRANSIENT );
}

add_action( 'admin_post_varconv_create', 'varconv_handle_create' );
function varconv_handle_create(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
	check_admin_referer( 'varconv_create_' . $key );
	if ( ! empty( $_POST['dismiss'] ) ) {
		varconv_dismiss( $key );
		varconv_back( array( 'varconv_done' => 'dismissed' ) );
	}
	$group = varconv_posted_group();
	try {
		$id = varconv_create_posted( $group );
	} catch ( RuntimeException $e ) {
		// Shown in the group's card, refilled with what was submitted.
		set_transient( 'varconv_posted_' . get_current_user_id(), array_merge( $group, array( 'key' => $key, 'error' => $e->getMessage() ) ), 5 * MINUTE_IN_SECONDS );
		// Products picked in Products > All Products come back to their card.
		$combine = isset( $_POST['combine'] ) ? implode( ',', array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['combine'] ) ) ) ) ) : '';
		varconv_back( '' !== $combine ? array( 'combine' => $combine ) : array(), 'varconv-' . $key );
	}
	delete_transient( 'varconv_posted_' . get_current_user_id() );
	varconv_back( array( 'varconv_done' => 'created', 'varconv_product' => $id ) );
}

/** "Create selected groups" without scripts: the checked groups, or all that match the filters, in one request. */
add_action( 'admin_post_varconv_bulk', 'varconv_handle_bulk' );
function varconv_handle_bulk(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter' ), 403 );
	}
	check_admin_referer( 'varconv_bulk' );
	if ( ! empty( $_POST['all'] ) ) {
		wp_parse_str( isset( $_POST['filters'] ) ? sanitize_text_field( wp_unslash( $_POST['filters'] ) ) : '', $filters );
		$keys = array_column( varconv_filtered( varconv_candidates()['collections'], varconv_filters( $filters ) ), 'key' );
	} else {
		$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['keys'] ) ) : array();
	}
	try {
		$counts = varconv_bulk_create( $keys );
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array() );
	}
	varconv_back( array( 'varconv_done' => 'bulk', 'varconv_made' => $counts['made'], 'varconv_skip' => $counts['skip'], 'varconv_failed_count' => $counts['failed'] ) );
}

/**
 * Creates the groups $keys as the scan proposed them, under the bulk lock.
 *
 * @param string[] $keys
 * @return array{made:int,skip:int,failed:int,results:array<string,array>}
 * @throws RuntimeException When another bulk run holds the lock.
 */
function varconv_bulk_create( array $keys ): array {
	if ( ! varconv_lock() ) {
		throw new RuntimeException( esc_html__( 'Another bulk run is in progress. Try again in a few minutes.', 'variation-converter' ) );
	}
	$counts = array( 'made' => 0, 'skip' => 0, 'failed' => 0, 'results' => array() );
	try {
		$wanted = array_flip( $keys );
		foreach ( varconv_bulk_candidates() as $c ) {
			if ( ! isset( $wanted[ $c['key'] ] ) ) {
				continue;
			}
			$result = varconv_convert( $c );
			$counts['made'] += 'made' === $result['status'] ? 1 : 0;
			$counts['skip'] += 'skip' === $result['status'] ? 1 : 0;
			$counts['failed'] += 'failed' === $result['status'] ? 1 : 0;
			$counts['results'][ $c['key'] ] = $result;
			varconv_lock_renew();
		}
	} finally {
		varconv_unlock();
	}
	return $counts;
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
		} elseif ( varconv_combine_ids() ) {
			varconv_render_manual( varconv_combine_ids() );
		} else {
			varconv_render_combine();
		}
		?>
	</div>
	<?php
}

/** An Undo button for a conversion, posting to the given admin-post action; assets/admin.js asks first. */
function varconv_undo_form( string $action, int $id, string $label = '' ): void {
	$confirm = 'varconv_undo' === $action
		? __( 'Delete this variable product and show its simple products again?', 'variation-converter' )
		: __( 'Delete the simple products made from this product and show it again?', 'variation-converter' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-undo" data-confirm="<?php echo esc_attr( $confirm ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<input type="hidden" name="tab" value="history">
		<input type="hidden" name="product" value="<?php echo (int) $id; ?>">
		<?php wp_nonce_field( $action . '_' . $id ); ?>
		<button class="button"><?php echo esc_html( '' !== $label ? $label : __( 'Undo', 'variation-converter' ) ); ?></button>
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
			echo '<div class="notice notice-success varconv-created">' . varconv_created_html( $id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built.
			break;
		case 'bulk':
			$made   = isset( $_GET['varconv_made'] ) ? absint( wp_unslash( $_GET['varconv_made'] ) ) : 0;
			$skip   = isset( $_GET['varconv_skip'] ) ? absint( wp_unslash( $_GET['varconv_skip'] ) ) : 0;
			$failed = isset( $_GET['varconv_failed_count'] ) ? absint( wp_unslash( $_GET['varconv_failed_count'] ) ) : 0;
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$failed ? 'warning' : 'success',
				wp_kses_post( sprintf(
					/* translators: 1: products made, 2: groups skipped, 3: groups that failed, 4: link to the History tab. */
					__( 'Created %1$d variable products; %2$d groups were skipped because they need a label checked, and %3$d failed. Undo is in <a href="%4$s">History</a>.', 'variation-converter' ),
					$made,
					$skip,
					$failed,
					esc_url( varconv_url( array( 'tab' => 'history' ) ) )
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
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html__( 'Skipped. Later scans leave this group out.', 'variation-converter' ) );
			break;
	}
}
