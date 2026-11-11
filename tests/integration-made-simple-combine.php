<?php
/**
 * Products Make simple made can be combined again, or added to a combined product, which forgets that Make simple;
 * and each product Combine leaves out says why. Against a real WordPress + WooCommerce:
 *   wp eval-file tests/integration-made-simple-combine.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_forget_made_simple' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$cleanup = array();
$tag = strtoupper( 'vcms' . wp_rand( 1000, 9999 ) );
try {
	$simple = static function ( string $sku, string $name, string $visibility = 'visible' ) use ( &$cleanup ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_sku( $sku );
		$p->set_regular_price( '10' );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( $visibility );
		return $cleanup[] = $p->save();
	};
	$a = $simple( $tag . '-73', 'VC Stroker 73' );
	$b = $simple( $tag . '-77', 'VC Stroker 77' );
	$c = $simple( $tag . '-85', 'VC Stroker 85' );

	// 1. Combine two, then Make simple on both: the variable product goes to the Trash.
	$first = $cleanup[] = varconv_create( 'VC Stroker', 'Size', array( $a => '73', $b => '77' ) );
	$children = array_map( 'intval', wc_get_product( $first )->get_children() );
	$cleanup = array_merge( $cleanup, $children );
	varconv_take_out( $first, array_fill_keys( $children, '' ) );
	check( get_post_status( $first ), 'trash', 'first product in the Trash' );
	check( (int) get_post_meta( $a, VARCONV_TAKEN_META, true ) > 0, true, 'made simple' );
	check( in_array( $a, array_column( varconv_products(), 'id' ), true ), false, 'the scan still leaves it out' );
	$loose = array_column( varconv_candidates( true )['loose'], 'id' );
	check( in_array( $a, $loose, true ), true, 'search finds it' );

	// 2. Selected by hand, they can be combined, with the third.
	$found = varconv_products_by_id( array( $a, $b, $c ) );
	check( $found['refused'], array(), 'none refused' );
	check( array_keys( varconv_made_simple_from( array( $a, $b, $c ) ) ), array( $first ), 'warns about the first product' );
	$second = $cleanup[] = varconv_create( 'VC Stroker Two', 'Size', array( $a => '73', $b => '77', $c => '85' ) );
	$cleanup = array_merge( $cleanup, array_map( 'intval', wc_get_product( $second )->get_children() ) );
	check( count( varconv_members( $second ) ), 3, 'three members' );
	check( get_post_meta( $a, VARCONV_TAKEN_META, true ), '', 'Make simple forgotten' );
	check( (int) get_post_meta( $a, VARCONV_TAKEN_WAS_META, true ), $children[0], 'variation kept for redirects' );
	check( get_post_meta( $a, VARCONV_TIME_META, true ), '', 'no Make simple time' );
	check( in_array( $a, array_column( varconv_history(), 'id' ), true ), false, 'Make simple row gone from History' );
	check( in_array( $second, array_column( varconv_history(), 'id' ), true ), true, 'new combine in History' );
	check( get_post_status( $first ), 'trash', 'first product left in the Trash' );
	// The first product's old URL still leads to the simple product, whose page redirects to its new variation.
	check( varconv_made_simple_target( $children, array(), true ), $a, 'old URL target' );
	check( '' !== varconv_combined_url( $a ), true, 'simple product redirects to the new product' );

	// 3. Undo of the new combine leaves them as ordinary simple products.
	check( varconv_undo( $second ), 3, 'Undo restores three' );
	check( wc_get_product( $a )->get_catalog_visibility(), 'visible', 'visible again' );
	check( get_post_meta( $a, VARCONV_TAKEN_META, true ), '', 'still not made simple' );

	// 4. Add to a combined product takes a made-simple product too.
	$third = $cleanup[] = varconv_create( 'VC Stroker Three', 'Size', array( $a => '73', $b => '77' ) );
	$third_children = array_map( 'intval', wc_get_product( $third )->get_children() );
	$cleanup = array_merge( $cleanup, $third_children );
	varconv_take_out( $third, array( $third_children[1] => '' ) );
	check( (int) get_post_meta( $b, VARCONV_TAKEN_META, true ), $third_children[1], 'b made simple again' );
	check( varconv_add_problem( wc_get_product( $b ) ), '', 'b can be added' );
	$fourth = $cleanup[] = varconv_create( 'VC Stroker Four', 'Size', array( $c => '85', $simple( $tag . '-89', 'VC Stroker 89' ) => '89' ) );
	$cleanup = array_merge( $cleanup, array_map( 'intval', wc_get_product( $fourth )->get_children() ) );
	$cleanup = array_merge( $cleanup, varconv_add_to( $fourth, array( $b => array( '77' ) ) ) );
	check( get_post_meta( $b, VARCONV_TAKEN_META, true ), '', 'Add forgets Make simple' );
	check( (int) get_post_meta( $b, VARCONV_PARENT_META, true ), $fourth, 'b is in the fourth product' );

	// 5. Each product left out says why.
	$hidden = $simple( $tag . '-97', 'VC Stroker 97', 'hidden' );
	$no_sku = $simple( '', 'VC Stroker no SKU' );
	check( varconv_products_by_id( array( $hidden, $no_sku, $c ) )['refused'], array( $hidden, $no_sku, $c ), 'three refused' );
	check( str_contains( varconv_combine_problem( $hidden ), 'Hidden from the shop' ), true, 'hidden reason' );
	check( str_contains( varconv_combine_problem( $no_sku ), 'No SKU' ), true, 'no SKU reason' );
	check( str_contains( varconv_combine_problem( $c ), 'Already in a combined product' ), true, 'combined reason' );

	echo "Made-simple combine integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $id ) {
		if ( get_post( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
}
