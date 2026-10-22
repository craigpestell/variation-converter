<?php
/**
 * Split and Undo against a real WordPress + WooCommerce, with the plugin active:
 *   wp eval-file tests/integration-split.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_split_product' ) ) {
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
		check( str_contains( $e->getMessage(), $needle ), true, $what . ' (' . $e->getMessage() . ')' );
		return;
	}
	throw new RuntimeException( $what . ': no exception' );
}

$cleanup = array();
$tag = 'vctest' . wp_rand( 1000, 9999 );
try {
	// A global attribute (Color) and a custom one (Size), plus a non-variation attribute (Material).
	$attribute_id = wc_create_attribute( array( 'name' => 'VC Test Color', 'slug' => $tag ) );
	$taxonomy = 'pa_' . $tag;
	register_taxonomy( $taxonomy, 'product' );
	$black = wp_insert_term( 'Jet Black', $taxonomy, array( 'slug' => 'jet-black' ) )['term_id'];
	$blue = wp_insert_term( 'Blue', $taxonomy, array( 'slug' => 'blue' ) )['term_id'];
	$cat = wp_insert_term( 'VC Test Cat ' . $tag, 'product_cat' )['term_id'];

	$color = new WC_Product_Attribute();
	$color->set_id( $attribute_id );
	$color->set_name( $taxonomy );
	$color->set_options( array( $black, $blue ) );
	$color->set_variation( true );
	$color->set_visible( true );
	$size = new WC_Product_Attribute();
	$size->set_name( 'Size' );
	$size->set_options( array( 'Small', 'Large' ) );
	$size->set_variation( true );
	$material = new WC_Product_Attribute();
	$material->set_name( 'Material' );
	$material->set_options( array( 'Cotton' ) );
	$material->set_visible( true );

	$parent = new WC_Product_Variable();
	$parent->set_name( 'VC Test Shirt' );
	$parent->set_status( 'publish' );
	$parent->set_sku( strtoupper( $tag ) );
	$parent->set_description( 'Parent description' );
	$parent->set_short_description( 'Parent short' );
	$parent->set_weight( '0.5' );
	$parent->set_category_ids( array( $cat ) );
	$parent->set_attributes( array( $color, $size, $material ) );
	$parent_id = $parent->save();
	$cleanup[] = $parent_id;

	$variations = array();
	foreach ( array(
		array( 'jet-black', 'Small', '10', 5, '' ),
		array( 'jet-black', 'Large', '12', null, 'Large black description' ),
		array( 'blue', 'Small', '11', null, '' ),
		array( 'blue', '', '11', null, '' ), // "Any" size: cannot be one product.
	) as $i => [ $c, $s, $price, $stock, $description ] ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_attributes( array( $taxonomy => $c, 'size' => $s ) );
		$v->set_status( 'publish' );
		$v->set_sku( strtoupper( $tag ) . '-' . $i );
		$v->set_regular_price( $price );
		$v->set_description( $description );
		if ( null !== $stock ) {
			$v->set_manage_stock( true );
			$v->set_stock_quantity( $stock );
		}
		$variations[] = $v->save();
	}
	WC_Product_Variable::sync( $parent_id );
	$parent = wc_get_product( $parent_id );

	// The review card's rows.
	$rows = varconv_split_rows( $parent );
	check( array_column( $rows, 'name' ), array( 'VC Test Shirt Jet Black Small', 'VC Test Shirt Jet Black Large', 'VC Test Shirt Blue Small', 'VC Test Shirt Blue' ), 'row names use term names' );
	check( array_map( static fn( $r ) => '' !== $r['problem'], $rows ), array( false, false, false, true ), '"Any" variation flagged' );

	// Refusals leave nothing behind.
	throws( static fn() => varconv_split_product( $parent_id, array( $variations[3] => 'X' ) ), '"Any"', 'any refused' );
	throws( static fn() => varconv_split_product( $parent_id, array( $variations[0] => '' ) ), 'needs a product name', 'empty name refused' );
	throws( static fn() => varconv_split_product( $parent_id, array( 999999 => 'X' ) ), 'not a variation', 'foreign ID refused' );
	check( '', (string) wc_get_product( $parent_id )->get_meta( VARCONV_SPLIT_META ), 'nothing split after refusals' );

	$names = array();
	foreach ( array_slice( $rows, 0, 3 ) as $r ) {
		$names[ $r['id'] ] = $r['name'];
	}
	$made = varconv_split_product( $parent_id, $names );
	$cleanup = array_merge( $cleanup, $made );
	check( count( $made ), 3, 'three simple products' );

	$first = wc_get_product( $made[0] );
	check( $first->get_type(), 'simple', 'type' );
	check( $first->get_status(), 'publish', 'status' );
	check( $first->get_name(), 'VC Test Shirt Jet Black Small', 'name' );
	check( $first->get_sku(), strtoupper( $tag ) . '-0', 'SKU reused' );
	check( $first->get_regular_price(), '10', 'price' );
	check( $first->get_manage_stock(), true, 'own stock kept' );
	check( $first->get_stock_quantity(), 5, 'stock quantity' );
	check( $first->get_weight(), '0.5', 'weight from parent' );
	check( $first->get_description(), 'Parent description', 'parent description' );
	check( $first->get_short_description(), 'Parent short', 'parent short description' );
	check( $first->get_category_ids(), array( $cat ), 'categories from parent' );
	check( array_keys( $first->get_attributes() ), array( 'material' ), 'non-variation attributes copied' );
	check( (string) $first->get_meta( VARCONV_SPLIT_FROM_META ), (string) $parent_id, 'split marker' );
	check( wc_get_product( $made[1] )->get_description(), 'Large black description', 'variation description wins' );
	check( wc_get_product( $made[1] )->get_manage_stock(), false, 'parent-managed stock not copied' );

	$parent = wc_get_product( $parent_id );
	check( $parent->get_catalog_visibility(), 'hidden', 'parent hidden' );
	check( json_decode( (string) $parent->get_meta( VARCONV_SPLIT_META ), true ), $made, 'parent records the new products' );
	check( in_array( $parent_id, varconv_split_list(), true ), true, 'listed in History' );
	check( in_array( $parent_id, varconv_variables( '', 1, 100 )['ids'], true ), false, 'not offered for split again' );
	check( array_intersect( $made, array_column( varconv_products(), 'id' ) ), array(), 'new products kept out of the Combine scan' );
	throws( static fn() => varconv_split_product( $parent_id, $names ), 'already split', 'second split refused' );

	check( varconv_split_undo( $parent_id ), 3, 'undo deletes three' );
	foreach ( $made as $id ) {
		check( get_post( $id ), null, 'simple ' . $id . ' deleted' );
	}
	$parent = wc_get_product( $parent_id );
	check( $parent->get_catalog_visibility(), 'visible', 'parent visible again' );
	check( (string) $parent->get_meta( VARCONV_SPLIT_META ), '', 'split marker removed' );
	check( (string) $parent->get_meta( VARCONV_HIDDEN_META ), '', 'visibility marker removed' );
	check( count( $parent->get_children() ), 4, 'variations untouched' );
	throws( static fn() => varconv_split_undo( $parent_id ), 'not split', 'undo twice refused' );

	// A product combined here is undone, not split.
	$simples = array();
	foreach ( array( 'Red', 'Green' ) as $i => $label ) {
		$s = new WC_Product_Simple();
		$s->set_name( 'VC Test Mug ' . $label );
		$s->set_status( 'publish' );
		$s->set_sku( strtoupper( $tag ) . '-M' . $i );
		$s->set_regular_price( '5' );
		$simples[ $s->save() ] = $label;
	}
	$cleanup = array_merge( $cleanup, array_keys( $simples ) );
	$combined = varconv_create( 'VC Test Mug', 'Color', '', $simples );
	$cleanup[] = $combined;
	check( in_array( $combined, varconv_variables( '', 1, 100 )['ids'], true ), true, 'combined product listed on Split tab' );
	throws( static fn() => varconv_split_product( $combined, array( wc_get_product( $combined )->get_children()[0] => 'X' ) ), 'Undo it instead', 'combined product refused' );
	check( varconv_undo( $combined ), 2, 'combine undone' );

	echo "Split integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $id ) {
		$product = wc_get_product( $id );
		foreach ( $product ? $product->get_children() : array() as $child ) {
			wp_delete_post( $child, true );
		}
		wp_delete_post( $id, true );
	}
	if ( ! empty( $cat ) ) {
		wp_delete_term( $cat, 'product_cat' );
	}
	if ( ! empty( $attribute_id ) ) {
		wc_delete_attribute( $attribute_id );
	}
}
