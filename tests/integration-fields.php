<?php
/**
 * Combine copies each simple product's fields onto its variation, and Split copies them back, against a real
 * WordPress + WooCommerce with the plugin active:
 *   wp eval-file tests/integration-fields.php
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

$cleanup = array();
$tag = 'vcfields' . wp_rand( 1000, 9999 );
$gtins = method_exists( 'WC_Product', 'get_global_unique_id' );
try {
	$make = static function ( string $label, string $gtin, string $tax_class ) use ( $tag, $gtins, &$cleanup ): int {
		$p = new WC_Product_Simple();
		$p->set_name( 'VC Fields Oil ' . $label );
		$p->set_status( 'publish' );
		$p->set_sku( strtoupper( $tag ) . '-' . strtoupper( $label ) );
		$p->set_regular_price( '10' );
		$p->set_description( $label . ' description' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 12 );
		$p->set_low_stock_amount( 3 );
		$p->set_tax_class( $tax_class );
		if ( $gtins ) {
			$p->set_global_unique_id( $gtin );
		}
		$p->update_meta_data( 'hwp_product_gtin', 'meta-' . $gtin );
		$p->update_meta_data( '_private_note', 'stays' );
		$id = $p->save();
		$cleanup[] = $id;
		return $id;
	};
	$a = $make( 'Rose', '0' . wp_rand( 10000000000, 99999999999 ), '' );
	$b = $make( 'Mint', '0' . wp_rand( 10000000000, 99999999999 ), 'reduced-rate' );

	$parent_id = varconv_create( 'VC Fields Oil', 'Scent', array( $a => 'Rose', $b => 'Mint' ) );
	$cleanup[] = $parent_id;
	$parent = wc_get_product( $parent_id );
	check( $parent->get_meta( 'hwp_product_gtin' ), '', 'The variable product gets no barcode field' );
	if ( $gtins ) {
		check( $parent->get_global_unique_id( 'edit' ), '', 'The variable product gets no GTIN' );
	}
	$variations = array_map( 'wc_get_product', $parent->get_children() );
	$by_twin = array();
	foreach ( $variations as $v ) {
		$by_twin[ (int) $v->get_meta( VARCONV_TWIN_META ) ] = $v;
	}
	foreach ( array( $a, $b ) as $id ) {
		$simple = wc_get_product( $id );
		$v = $by_twin[ $id ];
		check( $v->get_description( 'edit' ), $simple->get_description( 'edit' ), 'The variation takes its own description' );
		check( (int) $v->get_low_stock_amount( 'edit' ), 3, 'The variation takes the low stock threshold' );
		check( $v->get_meta( 'hwp_product_gtin' ), $simple->get_meta( 'hwp_product_gtin' ), 'Public custom fields are copied' );
		check( $v->get_meta( '_private_note' ), '', 'Private fields are not' );
		if ( $gtins ) {
			check( $v->get_global_unique_id( 'edit' ), $simple->get_global_unique_id( 'edit' ), 'The variation takes the GTIN, though the hidden simple product keeps it too' );
		}
	}
	check( $by_twin[ $a ]->get_tax_class( 'edit' ), 'parent', 'A tax class the same as the variable product\'s reads "same as parent"' );
	check( $by_twin[ $b ]->get_tax_class( 'edit' ), 'reduced-rate', 'A different tax class is kept' );
	echo "Fields integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			foreach ( $product->get_children() as $child ) {
				wp_delete_post( $child, true );
			}
		}
		wp_delete_post( $id, true );
	}
}
