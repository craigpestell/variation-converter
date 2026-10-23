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
		__( 'Variation Converter', 'variation-converter-for-woocommerce' ),
		__( 'Variation Converter', 'variation-converter-for-woocommerce' ),
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
	// jQuery UI Sortable (shipped with WordPress) for dragging rows in the review card.
	wp_enqueue_script( 'varconv-admin', $url . 'admin.js', array( 'jquery-ui-sortable' ), VARCONV_VERSION, true );
	wp_localize_script( 'varconv-admin', 'varconv', array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'varconv_ajax' ),
		'price'   => varconv_price_settings(),
		'strings' => array(
			/* translators: 1: products checked, 2: products in the group. */
			'count'     => __( '%1$d of %2$d', 'variation-converter-for-woocommerce' ),
			'created'   => __( 'Created', 'variation-converter-for-woocommerce' ),
			'skippedBadge' => __( 'Skipped', 'variation-converter-for-woocommerce' ),
			'working'   => __( 'Working...', 'variation-converter-for-woocommerce' ),
			'failed'    => __( 'Something went wrong. Reload the page and try again.', 'variation-converter-for-woocommerce' ),
			/* translators: %d: number of products left in the group. */
			'skipped'   => __( 'Skipped. Later scans leave this group out.', 'variation-converter-for-woocommerce' ),
			'undone'    => __( 'Undone. The simple products are visible again; scan again to see them as a group.', 'variation-converter-for-woocommerce' ),
			/* translators: 1: groups done, 2: groups selected. */
			'progress'  => __( 'Creating groups: %1$d of %2$d done...', 'variation-converter-for-woocommerce' ),
			/* translators: 1: products made, 2: groups skipped, 3: groups that failed. */
			'bulkDone'  => __( 'Done: %1$d variable products created, %2$d groups skipped, %3$d failed. Reloading...', 'variation-converter-for-woocommerce' ),
			'noAction'  => __( 'Choose a bulk action first.', 'variation-converter-for-woocommerce' ),
			'noGroups'  => __( 'Select at least one group first.', 'variation-converter-for-woocommerce' ),
		),
	) );
}

/** @return array<string,string> Tab key => label. */
function varconv_tabs(): array {
	return array(
		'combine' => __( 'Combine', 'variation-converter-for-woocommerce' ),
		'split'   => __( 'Split', 'variation-converter-for-woocommerce' ),
		'history' => __( 'History', 'variation-converter-for-woocommerce' ),
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
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a row action's link carries its filters too.
	$filters = isset( $_POST['filters'] ) ? sanitize_text_field( wp_unslash( $_POST['filters'] ) ) : ( isset( $_GET['filters'] ) ? sanitize_text_field( wp_unslash( $_GET['filters'] ) ) : '' );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( '' !== $filters ) {
		wp_parse_str( $filters, $parsed );
		$keep = array_merge( $keep, varconv_filter_args( varconv_filters( $parsed ) ) );
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

/** Puts skipped groups back in the list. @param string[] $keys */
function varconv_restore( array $keys ): void {
	update_option( VARCONV_DISMISSED_OPTION, array_values( array_diff( (array) get_option( VARCONV_DISMISSED_OPTION, array() ), $keys ) ), false );
	delete_transient( VARCONV_SCAN_TRANSIENT );
}

/** Checks the capability and the nonce of a row action's link for one group, and returns the group's key. */
function varconv_group_action_key( string $action ): string {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
	check_admin_referer( $action . '_' . $key );
	return $key;
}

/** Row action "Create": the group as the scan suggested it. */
add_action( 'admin_post_varconv_create_one', 'varconv_handle_create_one' );
function varconv_handle_create_one(): void {
	$key = varconv_group_action_key( 'varconv_create_one' );
	try {
		$counts = varconv_bulk_create( array( $key ) );
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array() );
	}
	$result = $counts['results'][ $key ] ?? null;
	if ( ! $result || 'made' !== $result['status'] ) {
		// Needs a label checked, or the products changed since the scan: review it in its card.
		varconv_fail( $result ? $result['message'] : __( 'This group is no longer in the scan. Scan again to see the current groups.', 'variation-converter-for-woocommerce' ), $result ? array( 'open' => $key ) : array() );
	}
	varconv_back( array( 'varconv_done' => 'created', 'varconv_product' => $result['id'] ) );
}

/** Row action "Skip, not variants". */
add_action( 'admin_post_varconv_skip', 'varconv_handle_skip' );
function varconv_handle_skip(): void {
	varconv_dismiss( varconv_group_action_key( 'varconv_skip' ) );
	varconv_back( array( 'varconv_done' => 'dismissed' ) );
}

/** Row action "Restore to the list", in the Skipped view. */
add_action( 'admin_post_varconv_restore', 'varconv_handle_restore' );
function varconv_handle_restore(): void {
	varconv_restore( array( varconv_group_action_key( 'varconv_restore' ) ) );
	varconv_back( array( 'varconv_done' => 'restored', 'varconv_count' => 1 ) );
}

add_action( 'admin_post_varconv_create', 'varconv_handle_create' );
function varconv_handle_create(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
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

/** Bulk actions on the checked groups, or on all that match: create (without scripts; admin.js batches it), skip, restore. */
add_action( 'admin_post_varconv_bulk', 'varconv_handle_bulk' );
function varconv_handle_bulk(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	check_admin_referer( 'varconv_bulk' );
	$which = isset( $_POST['which'] ) && 'bottom' === $_POST['which'] ? 'bulk_action2' : 'bulk_action';
	$action = isset( $_POST[ $which ] ) ? sanitize_key( wp_unslash( $_POST[ $which ] ) ) : 'create';
	if ( ! empty( $_POST['all'] ) ) {
		wp_parse_str( isset( $_POST['filters'] ) ? sanitize_text_field( wp_unslash( $_POST['filters'] ) ) : '', $filters );
		$keys = array_column( varconv_filtered( varconv_candidates(), varconv_filters( $filters ) ), 'key' );
	} else {
		$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['keys'] ) ) : array();
	}
	if ( ! $keys || '' === $action ) {
		varconv_back( array() );
	}
	if ( 'skip' === $action ) {
		foreach ( $keys as $key ) {
			varconv_dismiss( $key );
		}
		varconv_back( array( 'varconv_done' => 'dismissed', 'varconv_count' => count( $keys ) ) );
	}
	if ( 'restore' === $action ) {
		varconv_restore( $keys );
		varconv_back( array( 'varconv_done' => 'restored', 'varconv_count' => count( $keys ) ) );
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
		throw new RuntimeException( esc_html__( 'Another bulk run is in progress. Try again in a few minutes.', 'variation-converter-for-woocommerce' ) );
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
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	$id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
	check_admin_referer( 'varconv_undo_' . $id );
	try {
		$restored = varconv_undo( $id );
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array() );
	}
	// Scan again now, so the page this returns to can show the Combine tab's count.
	varconv_candidates();
	varconv_back( array( 'varconv_done' => 'undone', 'varconv_restored' => $restored ) );
}

add_action( 'admin_post_varconv_rescan', 'varconv_handle_rescan' );
function varconv_handle_rescan(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
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
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
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
	// Scan again now, so the page this returns to can show the Combine tab's count.
	varconv_candidates();
	varconv_back( array( 'varconv_done' => 'split', 'varconv_product' => $id, 'varconv_count' => count( $created ) ) );
}

add_action( 'admin_post_varconv_split_undo', 'varconv_handle_split_undo' );
function varconv_handle_split_undo(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	$id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
	check_admin_referer( 'varconv_split_undo_' . $id );
	try {
		$deleted = varconv_split_undo( $id );
	} catch ( RuntimeException $e ) {
		varconv_fail( $e->getMessage(), array() );
	}
	// Scan again now, so the page this returns to can show the Combine tab's count.
	varconv_candidates();
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
		$actions['varconv_split'] = sprintf( '<a href="%s">%s</a>', esc_url( varconv_url( array( 'tab' => 'split', 'product' => $post->ID ) ) ), esc_html__( 'Split into simple products', 'variation-converter-for-woocommerce' ) );
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
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	$tab = varconv_tab();
	// Counts only from a scan already made: opening Split or History should not start one.
	$scan = get_transient( VARCONV_SCAN_TRANSIENT );
	$counts = array(
		'combine' => is_array( $scan ) ? count( $scan['collections'] ) : null,
		'split'   => null,
		'history' => count( varconv_created() ) + count( varconv_split_list() ),
	);
	if ( 'combine' === $tab && ! varconv_combine_ids() ) {
		$scan = varconv_candidates();
		$counts['combine'] = count( $scan['collections'] );
	}
	?>
	<div class="wrap varconv">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Variation Converter', 'variation-converter-for-woocommerce' ); ?></h1>
		<?php if ( 'combine' === $tab ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-rescan">
			<input type="hidden" name="action" value="varconv_rescan">
			<?php wp_nonce_field( 'varconv_rescan' ); ?>
			<button class="page-title-action"><?php esc_html_e( 'Scan again', 'variation-converter-for-woocommerce' ); ?></button>
			<?php if ( is_array( $scan ) ) : ?>
				<span class="varconv-scanned">
					<?php
					/* translators: %s: time since the scan, e.g. "5 mins". */
					echo esc_html( sprintf( __( 'Scanned %s ago', 'variation-converter-for-woocommerce' ), human_time_diff( (int) $scan['time'] ) ) );
					?>
				</span>
			<?php endif; ?>
		</form>
		<?php endif; ?>
		<hr class="wp-header-end">
		<nav class="nav-tab-wrapper">
			<?php foreach ( varconv_tabs() as $key => $label ) : ?>
				<a href="<?php echo esc_url( varconv_url( 'combine' === $key ? array() : array( 'tab' => $key ) ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><?php echo null !== $counts[ $key ] ? ' <span class="count">' . (int) $counts[ $key ] . '</span>' : ''; ?></a>
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
		? __( 'Delete this variable product and show its simple products again?', 'variation-converter-for-woocommerce' )
		: __( 'Delete the simple products made from this product and show it again?', 'variation-converter-for-woocommerce' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-undo" data-confirm="<?php echo esc_attr( $confirm ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<input type="hidden" name="tab" value="history">
		<input type="hidden" name="product" value="<?php echo (int) $id; ?>">
		<?php wp_nonce_field( $action . '_' . $id ); ?>
		<button class="button"><?php echo esc_html( '' !== $label ? $label : __( 'Undo', 'variation-converter-for-woocommerce' ) ); ?></button>
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
					_n( 'Created %1$d variable product; %2$d groups were skipped because they need a label checked, and %3$d failed. Undo is in <a href="%4$s">History</a>.', 'Created %1$d variable products; %2$d groups were skipped because they need a label checked, and %3$d failed. Undo is in <a href="%4$s">History</a>.', $made, 'variation-converter-for-woocommerce' ),
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
					_n( 'Deleted the variable product; %d simple product is visible again.', 'Deleted the variable product; %d simple products are visible again.', $restored, 'variation-converter-for-woocommerce' ),
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
					_n( 'Split %1$s into %2$d simple product. The variable product is now hidden; Undo is in <a href="%3$s">History</a>.', 'Split %1$s into %2$d simple products. The variable product is now hidden; Undo is in <a href="%3$s">History</a>.', $count, 'variation-converter-for-woocommerce' ),
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
					_n( 'Deleted %1$d simple product; %2$s is visible again.', 'Deleted %1$d simple products; %2$s is visible again.', $count, 'variation-converter-for-woocommerce' ),
					$count,
					get_post_field( 'post_title', $id, 'raw' )
				) )
			);
			break;
		case 'dismissed':
			$count = isset( $_GET['varconv_count'] ) ? absint( wp_unslash( $_GET['varconv_count'] ) ) : 1;
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of groups skipped. */
					_n( 'Skipped %d group. Later scans leave it out; the Skipped view lists it.', 'Skipped %d groups. Later scans leave them out; the Skipped view lists them.', $count, 'variation-converter-for-woocommerce' ),
					$count
				) )
			);
			break;
		case 'restored':
			$count = isset( $_GET['varconv_count'] ) ? absint( wp_unslash( $_GET['varconv_count'] ) ) : 1;
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of groups restored. */
					_n( 'Restored %d group to the list.', 'Restored %d groups to the list.', $count, 'variation-converter-for-woocommerce' ),
					$count
				) )
			);
			break;
	}
}
