<?php
/**
 * After a combine or split, a variation and its hidden twin share a SKU and GTIN; each can still be edited and
 * saved, while a duplicate on an unrelated product is still refused. Against a real WordPress + WooCommerce:
 *   wp eval-file tests/integration-twins.php
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

/** The WooCommerce error code a change throws, or '' when it doesn't. */
function refused( callable $change ): string {
	try {
		$change();
		return '';
	} catch ( WC_Data_Exception $e ) {
		return $e->getErrorCode();
	}
}

$cleanup = array();
$tag = 'VCTWIN' . wp_rand( 1000, 9999 );
$gtins = method_exists( 'WC_Product', 'get_global_unique_id' );
try {
	$ids = array();
	foreach ( array( 'S', 'M' ) as $label ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'VC Twin Tee ' . $label );
		$p->set_status( 'publish' );
		$p->set_sku( $tag . '-' . $label );
		$p->set_regular_price( '10' );
		if ( $gtins ) {
			$p->set_global_unique_id( '0' . wp_rand( 10000000000, 99999999999 ) );
		}
		$ids[ $label ] = $p->save();
		$cleanup[] = $ids[ $label ];
	}
	$parent_id = varconv_create( 'VC Twin Tee', 'Size', array( $ids['S'] => 'S', $ids['M'] => 'M' ) );
	$cleanup[] = $parent_id;
	$simple = wc_get_product( $ids['S'] );
	$variation = null;
	foreach ( wc_get_product( $parent_id )->get_children() as $child ) {
		if ( (int) get_post_meta( $child, VARCONV_TWIN_META, true ) === $ids['S'] ) {
			$variation = wc_get_product( $child );
		}
	}

	check( refused( static fn() => $variation->set_sku( $simple->get_sku() ) ), '', 'The variation keeps its SKU, shared with its hidden original' );
	check( refused( static fn() => $simple->set_sku( $simple->get_sku() ) ), '', 'The hidden original keeps its SKU' );
	check( refused( static fn() => $variation->set_sku( $tag . '-M' ) ), '', 'A SKU held only within the conversion is allowed' );
	$other = new WC_Product_Simple();
	$other->set_name( 'VC Twin Other' );
	$cleanup[] = $other->save();
	$other = wc_get_product( $other->get_id() );
	check( refused( static fn() => $other->set_sku( $simple->get_sku() ) ), 'product_invalid_sku', 'An unrelated product still cannot take the SKU' );
	if ( $gtins ) {
		check( refused( static fn() => $variation->set_global_unique_id( $simple->get_global_unique_id() ) ), '', 'The variation keeps its GTIN' );
		check( refused( static fn() => $simple->set_global_unique_id( $simple->get_global_unique_id() ) ), '', 'The hidden original keeps its GTIN' );
		check( refused( static fn() => $other->set_global_unique_id( $simple->get_global_unique_id() ) ), 'product_invalid_global_unique_id', 'An unrelated product still cannot take the GTIN' );
	}
	$variation->set_regular_price( '12' );
	check( $variation->save() > 0, true, 'The variation saves after an edit' );

	// The same after a split: the new simple products share SKUs with the hidden variations.
	varconv_undo( $parent_id );
	$variable = new WC_Product_Variable();
	$variable->set_name( 'VC Twin Mug' );
	$variable->set_status( 'publish' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( array( 'Small', 'Large' ) );
	$attribute->set_variation( true );
	$variable->set_attributes( array( $attribute ) );
	$mug = $variable->save();
	$cleanup[] = $mug;
	foreach ( array( 'Small', 'Large' ) as $label ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $mug );
		$v->set_attributes( array( 'size' => $label ) );
		$v->set_sku( $tag . '-MUG-' . $label );
		$v->set_regular_price( '8' );
		$v->set_status( 'publish' );
		$v->save();
	}
	WC_Product_Variable::sync( $mug );
	$names = array();
	foreach ( wc_get_product( $mug )->get_children() as $child ) {
		$names[ $child ] = 'VC Twin Mug ' . $child;
	}
	$made = varconv_split_product( $mug, $names );
	$cleanup = array_merge( $cleanup, $made );
	$split_simple = wc_get_product( $made[0] );
	check( refused( static fn() => $split_simple->set_sku( $split_simple->get_sku() ) ), '', 'A simple product made by a split keeps its SKU' );
	check( refused( static fn() => $other->set_sku( $split_simple->get_sku() ) ), 'product_invalid_sku', 'An unrelated product still cannot take a split SKU' );
	echo "Twin SKU and GTIN integration tests passed.\n";
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
