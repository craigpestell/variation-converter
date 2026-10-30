<?php
/**
 * Undo brings back current prices and stock, against a real WordPress + WooCommerce with the plugin active:
 *   wp eval-file tests/integration-undo-stock.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_twins' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$tag = 'VCU' . wp_rand( 1000, 9999 );
$cleanup = array();
$parent_id = 0;
try {
	// Combine, sell a variation, Undo: the simple product gets the variation's current stock and price.
	$ids = array();
	foreach ( array( 'Lavender', 'Rose' ) as $i => $label ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'VC Undo Soap ' . $label );
		$p->set_status( 'publish' );
		$p->set_sku( $tag . '-' . $i );
		$p->set_regular_price( '8' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 10 );
		$id = $p->save();
		$ids[ $id ] = $label;
		$cleanup[] = $id;
	}
	$keys = array_keys( $ids );
	$parent_id = varconv_create( 'VC Undo Soap', 'Scent', $ids );
	$twins = varconv_twins( $parent_id, $keys );
	check( count( $twins ), 2, 'every variation has its simple product' );
	$variation = wc_get_product( array_search( $keys[0], $twins, true ) );
	$variation->set_stock_quantity( 7 );
	$variation->set_regular_price( '9' );
	$variation->set_sale_price( '6' );
	$variation->save();

	varconv_undo( $parent_id );
	$parent_id = 0;
	$first = wc_get_product( $keys[0] );
	check( $first->get_stock_quantity(), 7, 'stock after sales comes back' );
	check( $first->get_regular_price(), '9', 'new regular price comes back' );
	check( $first->get_sale_price(), '6', 'new sale price comes back' );
	check( wc_get_product( $keys[1] )->get_stock_quantity(), 10, 'untouched product keeps its stock' );

	// Split, sell a simple product, Undo: the variation gets the simple product's current stock.
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'Small', 'Large' ) );
	$attr->set_variation( true );
	$attr->set_visible( true );
	$variable = new WC_Product_Variable();
	$variable->set_name( 'VC Undo Towel' );
	$variable->set_status( 'publish' );
	$variable->set_attributes( array( $attr ) );
	$split_id = $variable->save();
	$cleanup[] = $split_id;
	$names = array();
	foreach ( array( 'Small', 'Large' ) as $i => $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $split_id );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_sku( $tag . '-T' . $i );
		$v->set_regular_price( '20' );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 4 );
		$names[ $v->save() ] = 'VC Undo Towel ' . $size;
	}
	$cleanup = array_merge( $cleanup, array_keys( $names ) );
	$made = varconv_split_product( $split_id, $names );
	$cleanup = array_merge( $cleanup, $made );
	$simple = wc_get_product( $made[1] );
	$simple->set_stock_quantity( 1 );
	$simple->set_regular_price( '22' );
	$simple->save();

	varconv_split_undo( $split_id );
	$large = wc_get_product( array_keys( $names )[1] );
	check( $large->get_stock_quantity(), 1, 'variation stock after sales comes back' );
	check( $large->get_regular_price(), '22', 'variation price comes back' );
	check( wc_get_product( array_keys( $names )[0] )->get_stock_quantity(), 4, 'untouched variation keeps its stock' );
	check( (float) wc_get_product( $split_id )->get_variation_regular_price( 'max' ), 22.0, 'parent price range synced' );

	// No SKUs and only some variations split: pairs come from the recorded twin, not from position.
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Color' );
	$attr->set_options( array( 'Red', 'Green', 'Blue' ) );
	$attr->set_variation( true );
	$attr->set_visible( true );
	$variable = new WC_Product_Variable();
	$variable->set_name( 'VC Undo Mug' );
	$variable->set_status( 'publish' );
	$variable->set_attributes( array( $attr ) );
	$mug_id = $variable->save();
	$cleanup[] = $mug_id;
	$colors = array();
	foreach ( array( 'Red', 'Green', 'Blue' ) as $color ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $mug_id );
		$v->set_attributes( array( 'color' => $color ) );
		$v->set_regular_price( '12' );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 6 );
		$colors[ $color ] = $v->save();
	}
	$cleanup = array_merge( $cleanup, array_values( $colors ) );
	$made = varconv_split_product( $mug_id, array( $colors['Blue'] => 'VC Undo Mug Blue', $colors['Green'] => 'VC Undo Mug Green' ) );
	$cleanup = array_merge( $cleanup, $made );
	check( varconv_twins( $mug_id, array_reverse( $made ) ), array( $colors['Green'] => $made[1], $colors['Blue'] => $made[0] ), 'twins by recorded ID' );
	foreach ( array( 'VC Undo Mug Blue' => 2, 'VC Undo Mug Green' => 3 ) as $name => $stock ) {
		foreach ( $made as $id ) {
			$p = wc_get_product( $id );
			if ( $p->get_name() === $name ) {
				$p->set_stock_quantity( $stock );
				$p->save();
			}
		}
	}
	varconv_split_undo( $mug_id );
	check( wc_get_product( $colors['Red'] )->get_stock_quantity(), 6, 'unsplit variation untouched' );
	check( wc_get_product( $colors['Green'] )->get_stock_quantity(), 3, 'green gets its own stock' );
	check( wc_get_product( $colors['Blue'] )->get_stock_quantity(), 2, 'blue gets its own stock' );
	echo "Undo stock integration tests passed.\n";
} finally {
	if ( $parent_id ) {
		varconv_delete( $parent_id );
	}
	foreach ( $cleanup as $id ) {
		wp_delete_post( $id, true );
	}
}
