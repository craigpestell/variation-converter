<?php
/**
 * Combine gives every variation a price when its simple product has only an active price (`_price`, no
 * `_regular_price`, as on many imported products), and keeps $0 as a price, against a real WordPress + WooCommerce
 * with the plugin active:
 *   wp eval-file tests/integration-price.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_create' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$tag = 'VCP' . wp_rand( 1000, 9999 );
$cleanup = array();
try {
	// Each product has only `_price`, written straight to the meta as importers do.
	$make = static function ( string $label, string $price ) use ( $tag, &$cleanup ): int {
		$p = new WC_Product_Simple();
		$p->set_name( 'VC Price Wand ' . $label );
		$p->set_status( 'publish' );
		$p->set_sku( $tag . '-' . strtoupper( $label ) );
		$p->set_stock_status( 'instock' );
		$id = $p->save();
		$cleanup[] = $id;
		delete_post_meta( $id, '_regular_price' );
		update_post_meta( $id, '_price', $price );
		wc_delete_product_transients( $id );
		return $id;
	};
	$free = $make( 'Pink', '0' );
	$paid = $make( 'Blue', '37.74' );
	$found = array_column( varconv_products_by_id( array( $free, $paid ) )['products'], 'price', 'id' );
	check( array( $found[ $free ], $found[ $paid ] ), array( '0', '37.74' ), 'the scan reads the active price' );

	$parent_id = varconv_create( 'VC Price Wand', 'Color', array( $free => 'Pink', $paid => 'Blue' ) );
	$cleanup[] = $parent_id;
	$by_twin = array();
	foreach ( wc_get_product( $parent_id )->get_children() as $child ) {
		$by_twin[ (int) get_post_meta( $child, VARCONV_TWIN_META, true ) ] = $child;
	}
	foreach ( array( $free => '0', $paid => '37.74' ) as $id => $price ) {
		wc_delete_product_transients( $by_twin[ $id ] );
		$v = wc_get_product( $by_twin[ $id ] );
		check( $v->get_regular_price( 'edit' ), $price, "variation of #$id gets a regular price" );
		check( (string) get_post_meta( $v->get_id(), '_price', true ), $price, "variation of #$id gets _price" );
		check( $v->is_purchasable(), true, "variation of #$id can be bought" );
	}
	$parent = wc_get_product( $parent_id );
	check( $parent->is_purchasable(), true, 'the variable product can be bought' );
	check( array_values( $parent->get_variation_prices()['price'] ), array( '0.00', '37.74' ), 'the price range has both' );

	varconv_undo( $parent_id );
	$cleanup = array_diff( $cleanup, array( $parent_id ) );
	check( wc_get_product( $free )->get_price( 'edit' ), '0', 'Undo keeps the $0 price' );
	check( wc_get_product( $paid )->get_price( 'edit' ), '37.74', 'Undo keeps the price' );
	echo "Price tests passed.\n";
} finally {
	foreach ( $cleanup as $id ) {
		if ( get_post( $id ) && wc_get_product( $id ) instanceof WC_Product_Variable ) {
			varconv_delete( $id );
		} else {
			wp_delete_post( $id, true );
		}
	}
}
