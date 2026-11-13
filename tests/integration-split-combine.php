<?php
/**
 * Products a split made can be combined again, or added to a combined product, picked by hand. The split stays in
 * History, but its Undo waits until the products are out of the other product again. Against a real WordPress +
 * WooCommerce:
 *   wp eval-file tests/integration-split-combine.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_split_taken_by' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

function throws( callable $fn, string $needle, string $what ): void {
	try {
		$fn();
	} catch ( RuntimeException $e ) {
		$message = html_entity_decode( $e->getMessage(), ENT_QUOTES );
		check( str_contains( $message, $needle ), true, $what . ' (' . $message . ')' );
		return;
	}
	throw new RuntimeException( $what . ': no exception' );
}

function refused( callable $change ): string {
	try {
		$change();
		return '';
	} catch ( WC_Data_Exception $e ) {
		return $e->getErrorCode();
	}
}

$cleanup = array();
$tag = strtoupper( 'vcsc' . wp_rand( 1000, 9999 ) );
try {
	// A variable product with three sizes, split into three simple products.
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( array( 'Small', 'Medium', 'Large' ) );
	$attribute->set_variation( true );
	$attribute->set_visible( true );
	$variable = new WC_Product_Variable();
	$variable->set_name( 'VC Split Tee' );
	$variable->set_status( 'publish' );
	$variable->set_attributes( array( $attribute ) );
	$tee = $cleanup[] = $variable->save();
	foreach ( array( 'Small', 'Medium', 'Large' ) as $label ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $tee );
		$v->set_attributes( array( 'size' => $label ) );
		$v->set_sku( $tag . '-' . $label );
		$v->set_regular_price( '20' );
		$v->set_status( 'publish' );
		$cleanup[] = $v->save();
	}
	WC_Product_Variable::sync( $tee );
	$names = array();
	foreach ( wc_get_product( $tee )->get_children() as $child ) {
		$names[ $child ] = 'VC Split Tee ' . wc_get_product( $child )->get_attribute( 'size' );
	}
	$made = varconv_split_product( $tee, $names );
	$cleanup = array_merge( $cleanup, $made );
	[ $small, $medium, $large ] = $made;

	// 1. The scan still leaves them out, but search and picking by hand find them.
	check( array_intersect( $made, array_column( varconv_products(), 'id' ) ), array(), 'the scan leaves them out' );
	check( array_values( array_intersect( $made, array_column( varconv_candidates( true )['loose'], 'id' ) ) ), $made, 'search finds them' );
	check( varconv_products_by_id( $made )['refused'], array(), 'none refused' );
	check( varconv_add_problem( wc_get_product( $large ) ), '', 'Add takes them' );
	check( null !== varconv_split_from_html( $made ), true, 'the card names the split' );
	check( varconv_split_taken_by( $tee ), array(), 'nothing holds them yet' );

	// 2. Combine two of them: the split stays in History, but its Undo waits.
	$combined = $cleanup[] = varconv_create( 'VC Split Tee Two', 'Size', array( $small => 'Small', $medium => 'Medium' ) );
	$cleanup = array_merge( $cleanup, array_map( 'intval', wc_get_product( $combined )->get_children() ) );
	check( (int) get_post_meta( $small, VARCONV_PARENT_META, true ), $combined, 'small is in the new product' );
	check( (int) get_post_meta( $small, VARCONV_SPLIT_FROM_META, true ), $tee, 'split marker kept' );
	check( in_array( $tee, varconv_split_list(), true ), true, 'split still in History' );
	check( array_keys( varconv_split_taken_by( $tee ) ), array( $combined ), 'split held by the new product' );
	throws( static fn() => varconv_split_undo( $tee ), 'Undo that in History first', 'split Undo refused' );
	check( get_post_status( $large ), 'publish', 'refused Undo deleted nothing' );
	check( '' !== varconv_combined_url( $small ), true, 'the split product redirects to its new variation' );

	// Each SKU is on three products now (the split's variation, the simple product, the new variation); all still save.
	$new_variation = wc_get_product( wc_get_product( $combined )->get_children()[0] );
	$old_variation = wc_get_product( array_search( $small, varconv_twins( $tee, $made ), true ) );
	check( refused( static fn() => $new_variation->set_sku( $new_variation->get_sku() ) ), '', 'new variation keeps its SKU' );
	check( refused( static fn() => $old_variation->set_sku( $old_variation->get_sku() ) ), '', 'split variation keeps its SKU' );
	check( refused( static fn() => wc_get_product( $small )->set_sku( $tag . '-Small' ) ), '', 'hidden original keeps its SKU' );
	$other = new WC_Product_Simple();
	$other->set_name( 'VC Split Other' );
	$cleanup[] = $other->save();
	check( refused( static fn() => $other->set_sku( $tag . '-Small' ) ), 'product_invalid_sku', 'an unrelated product still cannot take it' );

	// 3. Add the third to the new product: still held.
	$cleanup = array_merge( $cleanup, varconv_add_to( $combined, array( $large => array( 'Large' ) ) ) );
	check( (int) get_post_meta( $large, VARCONV_PARENT_META, true ), $combined, 'large added' );

	// 4. Make simple on one of the new variations: the variation still stands for it, so Undo still waits.
	$children = array_map( 'intval', wc_get_product( $combined )->get_children() );
	varconv_take_out( $combined, array( $children[0] => '' ) );
	check( (int) get_post_meta( $small, VARCONV_PARENT_META, true ), 0, 'small out of the new product' );
	check( array_keys( varconv_split_taken_by( $tee ) ), array( $combined ), 'still held after Make simple' );
	check( varconv_take_out_undo( $small ) > 0, true, 'Make simple undone' );

	// 5. Undo the combine, then the split can be undone.
	check( varconv_undo( $combined ), 3, 'combine undone' );
	check( wc_get_product( $small )->get_catalog_visibility(), 'visible', 'visible again' );
	check( varconv_split_taken_by( $tee ), array(), 'nothing holds them' );
	check( varconv_split_undo( $tee ), 3, 'split undone' );
	check( get_post( $small ), null, 'split products deleted' );
	check( wc_get_product( $tee )->get_catalog_visibility(), 'visible', 'variable product back' );

	echo "Split combine integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $id ) {
		if ( get_post( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
}
