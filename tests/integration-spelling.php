<?php
/**
 * Attribute spelling against a real WordPress + WooCommerce, with the plugin active:
 *   wp eval-file tests/integration-spelling.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_respell' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

/** A variable product with custom attributes $names => options, a variation per first option pair. */
function spelling_product( string $title, array $names ): int {
	$attributes = array();
	foreach ( $names as $name => $options ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $name );
		$attribute->set_options( $options );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$attributes[] = $attribute;
	}
	$parent = new WC_Product_Variable();
	$parent->set_name( $title );
	$parent->set_status( 'publish' );
	$parent->set_attributes( $attributes );
	$first = array_key_first( $names );
	$parent->set_default_attributes( array( sanitize_title( $first ) => $names[ $first ][0] ) );
	$id = $parent->save();
	foreach ( $names[ $first ] as $option ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $id );
		$variation->set_regular_price( '10' );
		$values = array( sanitize_title( $first ) => $option );
		foreach ( array_slice( $names, 1, null, true ) as $name => $options ) {
			$values[ sanitize_title( $name ) ] = $options[0];
		}
		$variation->set_attributes( $values );
		$variation->save();
	}
	WC_Product_Variable::sync( $id );
	return $id;
}

$cleanup = array();
$saved = get_option( VARCONV_SPELLING_OPTION, '' );
try {
	update_option( VARCONV_SPELLING_OPTION, 'british' );
	check( varconv_attribute_name( 'Color' ), 'Colour', 'British store writes Colour' );
	check( varconv_attribute_name( 'flavour' ), 'Flavour', 'case fixed' );
	check( varconv_attribute_name( 'Size' ), 'Size', 'other names kept' );

	$a = spelling_product( 'VC Spelling Tee', array( 'Color' => array( 'Red', 'Blue' ), 'Size' => array( 'S' ) ) );
	$b = spelling_product( 'VC Spelling Lube', array( 'Flavor' => array( 'Cherry', 'Mint' ) ) );
	$c = spelling_product( 'VC Spelling Both', array( 'Color' => array( 'Red' ), 'Colour' => array( 'Blue' ) ) );
	$d = spelling_product( 'VC Spelling Right', array( 'Colour' => array( 'Red' ) ) );
	$cleanup = array( $a, $b, $c, $d );

	$found = array_intersect( varconv_misspelled_products(), $cleanup );
	check( array_values( $found ), array( $a, $b, $c ), 'products spelled the other way found' );

	check( varconv_respell( $a ), array( 'Color' => 'Colour' ), 'tee renamed' );
	$tee = wc_get_product( $a );
	check( array_keys( $tee->get_attributes() ), array( 'colour', 'size' ), 'tee attribute keys' );
	check( $tee->get_attributes()['colour']->get_name(), 'Colour', 'tee attribute name' );
	check( $tee->get_attributes()['colour']->get_options(), array( 'Red', 'Blue' ), 'tee options kept' );
	check( $tee->get_default_attributes(), array( 'colour' => 'Red' ), 'tee default choice' );
	$choices = array_map( static fn( $v ) => wc_get_product( $v )->get_attributes(), $tee->get_children() );
	check( $choices, array( array( 'colour' => 'Red', 'size' => 'S' ), array( 'colour' => 'Blue', 'size' => 'S' ) ), 'variations renamed' );
	check( metadata_exists( 'post', $tee->get_children()[0], 'attribute_color' ), false, 'old variation meta gone' );
	// A shopper's choice still finds its variation.
	$store = WC_Data_Store::load( 'product' );
	check( $store->find_matching_product_variation( $tee, array( 'attribute_colour' => 'Blue', 'attribute_size' => 'S' ) ), $tee->get_children()[1], 'variation matches' );

	// The bulk run covers the whole store, so the demo catalog's products count too.
	$before = varconv_misspelled_products();
	$result = varconv_respell_all();
	check( $result['done'] + count( $result['failed'] ), count( $before ), 'every product spelled the other way tried' );
	check( count( $result['failed'] ) >= 1, true, 'both spellings refused' );
	check( array_keys( wc_get_product( $b )->get_attributes() ), array( 'flavour' ), 'lube renamed' );
	check( array_keys( wc_get_product( $c )->get_attributes() ), array( 'color', 'colour' ), 'both spellings left alone' );
	check( array_values( array_intersect( varconv_misspelled_products(), $cleanup ) ), array( $c ), 'only the clash left' );

	// Combining writes the store's spelling, whatever was typed.
	$members = array();
	foreach ( array( 'Red', 'Blue' ) as $label ) {
		$simple = new WC_Product_Simple();
		$simple->set_name( 'VC Spelling Sock ' . $label );
		$simple->set_status( 'publish' );
		$simple->set_regular_price( '5' );
		$members[ $simple->save() ] = $label;
	}
	$cleanup = array_merge( $cleanup, array_keys( $members ) );
	$made = varconv_create( 'VC Spelling Sock', 'color', $members );
	$cleanup[] = $made;
	check( array_keys( wc_get_product( $made )->get_attributes() ), array( 'colour' ), 'combine writes Colour' );

	update_option( VARCONV_SPELLING_OPTION, 'american' );
	check( varconv_attribute_name( 'Colour' ), 'Color', 'American store writes Color' );
	check( varconv_respell( $d ), array( 'Colour' => 'Color' ), 'renames back the other way' );

	echo "Spelling integration tests passed.\n";
} finally {
	update_option( VARCONV_SPELLING_OPTION, $saved );
	foreach ( $cleanup as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			foreach ( $product->get_children() as $child ) {
				wp_delete_post( $child, true );
			}
			$product->delete( true );
		}
	}
}
