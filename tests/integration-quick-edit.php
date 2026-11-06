<?php
/**
 * Quick Edit variant stock, against a real WordPress + WooCommerce with the plugin active:
 *   wp eval-file tests/integration-quick-edit.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_create' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}
require_once dirname( __DIR__ ) . '/includes/admin-quick-edit.php'; // Admin only; WP-CLI is not admin.

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$tag = 'VCQ' . wp_rand( 1000, 9999 );
$cleanup = array();
$parent_id = 0;
$user = get_current_user_id();
$manage = get_option( 'woocommerce_manage_stock' );
try {
	update_option( 'woocommerce_manage_stock', 'yes' );
	wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
	$ids = array();
	foreach ( array( 'Black' => 4, 'Blue' => 0 ) as $label => $qty ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'VC Quick Tee ' . $label );
		$p->set_status( 'publish' );
		$p->set_sku( $tag . '-' . $label );
		$p->set_regular_price( '20' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $qty );
		$ids[ $p->save() ] = $label;
	}
	$cleanup = array_keys( $ids );
	$parent_id = varconv_create( 'VC Quick Tee', 'Color', $ids );
	$parent = wc_get_product( $parent_id );
	check( varconv_quick_stock_applies( $parent ), true, 'applies to a converted product' );
	check( varconv_quick_stock_applies( wc_get_product( $cleanup[0] ) ), false, 'not to a simple product' );

	$rows = varconv_variant_stock_rows( $parent );
	check( array_column( $rows, 'label' ), array( 'Black', 'Blue' ), 'labels in shop order' );
	check( array_column( $rows, 'qty' ), array( 4, 0 ), 'variant stock' );
	$by_label = array_combine( array_column( $rows, 'label' ), array_column( $rows, 'id' ) );

	// The Stock column shows the variants' total, not a stray count on the variable product.
	$parent->set_manage_stock( true );
	$parent->set_stock_quantity( 99 );
	$parent->save();
	$html = varconv_quick_stock_column( 'WooCommerce', wc_get_product( $parent_id ) );
	check( false !== strpos( $html, '(4)' ) && false !== strpos( $html, 'Black: 4, Blue: 0' ), true, 'column shows variants: ' . $html );

	// The row data lists both variants.
	ob_start();
	varconv_quick_stock_data( 'name', $parent_id );
	$div = ob_get_clean();
	check( false !== strpos( $div, 'data-all-own="1"' ), true, 'row data: ' . $div );

	// Save: Blue changed from 0 to 7; Black left at 4 while a sale took it to 3.
	wc_update_product_stock( $by_label['Black'], 3, 'set' );
	$_POST = array(
		'woocommerce_quick_edit_nonce' => wp_create_nonce( 'woocommerce_quick_edit_nonce' ),
		'varconv_stock'                => array( $by_label['Black'] => '4', $by_label['Blue'] => '7', $cleanup[0] => '50' ),
		'varconv_stock_was'            => array( $by_label['Black'] => '4', $by_label['Blue'] => '0', $cleanup[0] => '4' ),
	);
	varconv_quick_stock_save( wc_get_product( $parent_id ) );
	check( wc_get_product( $by_label['Blue'] )->get_stock_quantity(), 7, 'changed variant saved' );
	check( wc_get_product( $by_label['Blue'] )->get_stock_status(), 'instock', 'status follows stock' );
	check( wc_get_product( $by_label['Black'] )->get_stock_quantity(), 3, 'untouched variant keeps the sale' );
	check( wc_get_product( $cleanup[0] )->get_stock_quantity(), 4, 'a product outside this one is ignored' );

	// A bad nonce saves nothing.
	$_POST['woocommerce_quick_edit_nonce'] = 'nope';
	$_POST['varconv_stock'][ $by_label['Blue'] ] = '1';
	varconv_quick_stock_save( wc_get_product( $parent_id ) );
	check( wc_get_product( $by_label['Blue'] )->get_stock_quantity(), 7, 'bad nonce ignored' );

	// The filter can turn it off.
	add_filter( 'varconv_quick_edit_variant_stock', '__return_false' );
	check( varconv_quick_stock_applies( wc_get_product( $parent_id ) ), false, 'filter turns it off' );
	remove_filter( 'varconv_quick_edit_variant_stock', '__return_false' );
	echo "Quick Edit integration tests passed.\n";
} finally {
	$_POST = array();
	update_option( 'woocommerce_manage_stock', $manage );
	wp_set_current_user( $user );
	if ( $parent_id ) {
		varconv_delete( $parent_id );
	}
	foreach ( $cleanup as $id ) {
		wp_delete_post( $id, true );
	}
}
