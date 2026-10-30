<?php
/**
 * Undo checked in History undoes several conversions, combined and split, and reports the ones it can't, against a
 * real WordPress + WooCommerce with the plugin active:
 *   wp eval-file tests/integration-undo-many.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_undo_many' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$cleanup = array();
$tag = 'VCMANY' . wp_rand( 1000, 9999 );
try {
	$parents = array();
	foreach ( array( 'Cap', 'Mug' ) as $product ) {
		$ids = array();
		foreach ( array( 'Red', 'Blue' ) as $label ) {
			$p = new WC_Product_Simple();
			$p->set_name( "VC Many $product $label" );
			$p->set_status( 'publish' );
			$p->set_sku( "$tag-$product-$label" );
			$p->set_regular_price( '9' );
			$ids[ $p->save() ] = $label;
		}
		$cleanup = array_merge( $cleanup, array_keys( $ids ) );
		$parents[] = varconv_create( "VC Many $product", 'Color', $ids );
	}
	$cleanup = array_merge( $cleanup, $parents );
	// And a variable product made elsewhere, split here.
	$variable = new WC_Product_Variable();
	$variable->set_name( 'VC Many Tee' );
	$variable->set_status( 'publish' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( array( 'S', 'M' ) );
	$attribute->set_variation( true );
	$variable->set_attributes( array( $attribute ) );
	$tee = $variable->save();
	$cleanup[] = $tee;
	$names = array();
	foreach ( array( 'S', 'M' ) as $label ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $tee );
		$v->set_attributes( array( 'size' => $label ) );
		$v->set_sku( "$tag-TEE-$label" );
		$v->set_regular_price( '8' );
		$v->set_status( 'publish' );
		$names[ $v->save() ] = 'VC Many Tee ' . $label;
	}
	WC_Product_Variable::sync( $tee );
	$made = varconv_split_product( $tee, $names );
	$cleanup = array_merge( $cleanup, $made );

	$result = varconv_undo_many( array( $parents[0], 999999999 ) );
	check( $result['undone'], 1, 'One conversion undone' );
	check( count( $result['errors'] ), 1, 'A product not in History is reported' );
	check( get_post( $parents[0] ), null, 'The combined product is deleted' );
	foreach ( array_slice( $cleanup, 0, 2 ) as $simple ) {
		check( get_post_meta( $simple, VARCONV_PARENT_META, true ), '', 'Its simple products are back' );
	}

	$result = varconv_undo_many( array( $tee, $parents[1] ) );
	check( $result, array( 'undone' => 2, 'errors' => array() ), 'A combine and a split undone together' );
	check( get_post( $parents[1] ), null, 'The other combined product is deleted' );
	check( get_post( $made[0] ), null, 'The split\'s simple products are deleted' );
	check( get_post_meta( $tee, VARCONV_SPLIT_META, true ), '', 'The split variable product is back' );
	check( varconv_undo_many( array( $tee ) )['undone'], 0, 'Nothing left to undo' );
	echo "Undo checked integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			foreach ( $product->get_children() as $child ) {
				wp_delete_post( $child, true );
			}
			wp_delete_post( $id, true );
		}
	}
}
