<?php
/**
 * Products > All Products: combined products as groups. A variable product the plugin made gets a "variants" toggle
 * under its name that shows its variations as rows beneath it (thumbnail, options, SKU, price, stock, and the hidden
 * original each came from), loaded on demand from the REST route in rest-products.php. Expand all and Collapse all
 * sit above the list.
 *
 * With "Group combined products" on (the default, kept per user), the hidden originals of a combined product are left
 * out of the list, since they show inside its group. The Trash still lists them, and an original whose combined
 * product is in the Trash, or gone, is listed as usual.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_GROUP_META = 'varconv_group_products';

/** Whether the current user groups combined products on the Products list. On unless they turned it off. */
function varconv_grouping(): bool {
	return current_user_can( VARCONV_CAP ) && '0' !== (string) get_user_meta( get_current_user_id(), VARCONV_GROUP_META, true );
}

/**
 * The WHERE clause that leaves out hidden originals whose combined product is still there (not in the Trash).
 *
 * @param string $posts    The posts table.
 * @param string $postmeta The postmeta table.
 * @param string $key      The meta key that points an original at its combined product.
 */
function varconv_group_where( string $posts, string $postmeta, string $key ): string {
	return " AND NOT EXISTS ( SELECT 1 FROM {$postmeta} varconv_m JOIN {$posts} varconv_p ON varconv_p.ID = CAST( varconv_m.meta_value AS UNSIGNED )"
		. " WHERE varconv_m.post_id = {$posts}.ID AND varconv_m.meta_key = '" . esc_sql( $key ) . "'"
		. " AND varconv_p.post_type = 'product' AND varconv_p.post_status <> 'trash' )";
}

// Only the list's own query, and not in the Trash.
add_action( 'pre_get_posts', 'varconv_group_query' );
function varconv_group_query( WP_Query $query ): void {
	global $pagenow;
	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
		return;
	}
	if ( 'trash' === $query->get( 'post_status' ) || ! varconv_grouping() ) {
		return;
	}
	add_filter( 'posts_where', 'varconv_group_posts_where', 10, 2 );
}

function varconv_group_posts_where( string $where, WP_Query $query ): string {
	global $wpdb;
	if ( ! $query->is_main_query() ) {
		return $where;
	}
	remove_filter( 'posts_where', 'varconv_group_posts_where', 10 );
	return $where . varconv_group_where( $wpdb->posts, $wpdb->postmeta, VARCONV_PARENT_META );
}

// The toggle, under the name of each variable product the plugin made.
add_action( 'manage_product_posts_custom_column', 'varconv_group_toggle', 30, 2 );
function varconv_group_toggle( string $column, int $post_id ): void {
	if ( 'name' !== $column || ! current_user_can( VARCONV_CAP ) || '' === (string) get_post_meta( $post_id, VARCONV_META, true ) ) {
		return;
	}
	$product = wc_get_product( $post_id );
	if ( ! $product instanceof WC_Product_Variable ) {
		return;
	}
	$count = count( $product->get_children() );
	if ( ! $count ) {
		return;
	}
	/* translators: %d: number of variants. */
	$label = sprintf( _n( '%d variant', '%d variants', $count, 'variation-converter-for-woocommerce' ), $count );
	printf(
		'<button type="button" class="button-link varconv-group-toggle" aria-expanded="false" data-id="%1$d"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>%2$s</button>',
		absint( $post_id ),
		esc_html( $label )
	);
}

// "Group combined products", Expand all and Collapse all, above the list.
add_action( 'manage_posts_extra_tablenav', 'varconv_group_controls' );
function varconv_group_controls( string $which ): void {
	global $typenow;
	if ( 'top' !== $which || 'product' !== $typenow || ! current_user_can( VARCONV_CAP ) ) {
		return;
	}
	$on  = varconv_grouping();
	$url = wp_nonce_url( add_query_arg( 'varconv_group', $on ? '0' : '1' ), 'varconv_group', 'varconv_nonce' );
	?>
	<div class="alignleft actions varconv-group-controls">
		<label title="<?php esc_attr_e( 'Show the hidden simple products a combined product was made from only inside its group, not as rows of their own.', 'variation-converter-for-woocommerce' ); ?>">
			<input type="checkbox" class="varconv-group-switch" data-url="<?php echo esc_url( $url ); ?>" <?php checked( $on ); ?>>
			<?php esc_html_e( 'Group combined products', 'variation-converter-for-woocommerce' ); ?>
		</label>
		<button type="button" class="button varconv-expand-all hidden"><?php esc_html_e( 'Expand all', 'variation-converter-for-woocommerce' ); ?></button>
		<button type="button" class="button varconv-collapse-all hidden"><?php esc_html_e( 'Collapse all', 'variation-converter-for-woocommerce' ); ?></button>
	</div>
	<?php
}

// Saves the switch, then reloads the list without its query arguments.
add_action( 'load-edit.php', 'varconv_group_save' );
function varconv_group_save(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below before anything is saved.
	if ( ! isset( $_GET['varconv_group'] ) || ! current_user_can( VARCONV_CAP ) ) {
		return;
	}
	check_admin_referer( 'varconv_group', 'varconv_nonce' );
	$on = '1' === sanitize_key( wp_unslash( $_GET['varconv_group'] ) );
	update_user_meta( get_current_user_id(), VARCONV_GROUP_META, $on ? '1' : '0' );
	wp_safe_redirect( remove_query_arg( array( 'varconv_group', 'varconv_nonce' ) ) );
	exit;
}

add_action( 'admin_enqueue_scripts', 'varconv_group_assets' );
function varconv_group_assets( string $hook ): void {
	$screen = get_current_screen();
	if ( 'edit.php' !== $hook || ! $screen || 'edit-product' !== $screen->id || ! current_user_can( VARCONV_CAP ) ) {
		return;
	}
	$url = plugin_dir_url( VARCONV_FILE ) . 'assets/';
	wp_enqueue_style( 'varconv-product-groups', $url . 'product-groups.css', array(), VARCONV_VERSION );
	wp_enqueue_script( 'varconv-product-groups', $url . 'product-groups.js', array( 'jquery', 'wp-api-fetch' ), VARCONV_VERSION, true );
	wp_localize_script( 'varconv-product-groups', 'varconvGroups', array(
		'path'    => '/' . VARCONV_REST . '/products/variants',
		'loading' => __( 'Loading variants…', 'variation-converter-for-woocommerce' ),
		'failed'  => __( 'The variants could not be loaded.', 'variation-converter-for-woocommerce' ),
		'none'    => __( 'No variants.', 'variation-converter-for-woocommerce' ),
		/* translators: %s: the variant's SKU. */
		'sku'     => __( 'SKU %s', 'variation-converter-for-woocommerce' ),
		'kinds'   => array(
			'hidden' => __( 'Hidden original:', 'variation-converter-for-woocommerce' ),
			'trash'  => __( 'Original, in the Trash:', 'variation-converter-for-woocommerce' ),
			'taken'  => __( 'Made simple:', 'variation-converter-for-woocommerce' ),
		),
		'disabled' => __( 'Disabled', 'variation-converter-for-woocommerce' ),
		'view'     => __( 'View', 'variation-converter-for-woocommerce' ),
	) );
}
