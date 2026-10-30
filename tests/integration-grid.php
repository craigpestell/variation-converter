<?php
/**
 * Groups combined into one product with two attributes, against a real WordPress + WooCommerce, with the plugin
 * active:
 *   wp eval-file tests/integration-grid.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_create_grid' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$cleanup = array();
$tag = 'VCG' . wp_rand( 1000, 9999 );
try {
	$make = static function ( string $sku, string $name, string $price ) use ( &$cleanup ) {
		$s = new WC_Product_Simple();
		$s->set_name( $name );
		$s->set_status( 'publish' );
		$s->set_sku( $sku );
		$s->set_regular_price( $price );
		$s->set_manage_stock( true );
		$s->set_stock_quantity( 7 );
		return $cleanup[] = $s->save();
	};
	$st4 = $make( $tag . '-ST-4', $tag . ' Lube Strawberry 4oz', '5' );
	$st8 = $make( $tag . '-ST-8', $tag . ' Lube Strawberry 8oz', '9' );
	$ch4 = $make( $tag . '-CH-4', $tag . ' Lube Cherry 4oz', '5' );
	$ch8 = $make( $tag . '-CH-8', $tag . ' Lube Cherry 8oz', '9' );
	$ch16 = $make( $tag . '-CH-16', $tag . ' Lube Cherry 16oz', '15' );

	// A pair used twice is refused, and nothing is made.
	try {
		varconv_create_grid( $tag . ' Lube', 'Flavor', 'Size', '', array( $st4 => array( 'Strawberry', '4oz' ), $st8 => array( 'Strawberry', '4oz' ) ) );
		throw new LogicException( 'repeated pair accepted' );
	} catch ( RuntimeException $e ) {
		check( str_contains( html_entity_decode( $e->getMessage(), ENT_QUOTES ), '"Strawberry, 4oz" is used twice' ), true, 'repeated pair refused' );
	}
	check( (string) get_post_meta( $st4, VARCONV_PARENT_META, true ), '', 'nothing made after a refusal' );

	$id = $cleanup[] = varconv_create_grid( $tag . ' Lube', 'Flavor', 'Size', $tag, array(
		$st4  => array( 'Strawberry', '4oz' ),
		$st8  => array( 'Strawberry', '8oz' ),
		$ch4  => array( 'Cherry', '4oz' ),
		$ch8  => array( 'Cherry', '8oz' ),
		$ch16 => array( 'Cherry', '16oz' ),
	) );
	$parent = wc_get_product( $id );
	check( $parent->is_type( 'variable' ), true, 'variable product made' );
	$attributes = array_values( $parent->get_attributes() );
	check( array_map( static fn( $a ) => $a->get_name(), $attributes ), array( 'Flavor', 'Size' ), 'two attributes, groups first' );
	check( $attributes[0]->get_options(), array( 'Strawberry', 'Cherry' ), 'each flavor once' );
	check( $attributes[1]->get_options(), array( '4oz', '8oz', '16oz' ), 'each size once' );
	check( array_map( static fn( $a ) => $a->get_variation(), $attributes ), array( true, true ), 'both used for variations' );

	$variations = array();
	foreach ( $parent->get_children() as $child ) {
		$v = wc_get_product( $child );
		$variations[ $v->get_sku() ] = array( $v->get_attributes(), $v->get_regular_price(), $v->get_stock_quantity() );
	}
	check( count( $variations ), 5, 'one variation per product, Strawberry 16oz not made' );
	check( $variations[ $tag . '-CH-16' ], array( array( 'flavor' => 'Cherry', 'size' => '16oz' ), '15', 7 ), 'variation pair, price and stock' );
	check( $variations[ $tag . '-ST-8' ][0], array( 'flavor' => 'Strawberry', 'size' => '8oz' ), 'second variation pair' );

	// The old page sends shoppers to the variable product with both options chosen.
	$url = varconv_combined_url( $ch8 );
	check( str_contains( $url, 'attribute_flavor=Cherry' ) && str_contains( $url, 'attribute_size=8oz' ), true, 'redirect chooses both options (' . $url . ')' );

	check( varconv_undo( $id ), 5, 'undo restores every product' );
	array_pop( $cleanup );
	check( wc_get_product( $id ), false, 'undo deletes the variable product' );
	check( wc_get_product( $ch16 )->get_catalog_visibility(), 'visible', 'undo shows the products again' );

	// The single-attribute create still works as before.
	$single = $cleanup[] = varconv_create( $tag . ' Lube Cherry', 'Size', '', array( $ch4 => '4oz', $ch8 => '8oz' ) );
	check( array_keys( wc_get_product( $single )->get_attributes() ), array( 'size' ), 'single attribute product' );
	varconv_undo( $single );
	array_pop( $cleanup );

	echo "Grid integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $pid ) {
		$product = wc_get_product( $pid );
		if ( $product ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child ) {
					wp_delete_post( $child, true );
				}
			}
			wp_delete_post( $pid, true );
		}
	}
}
