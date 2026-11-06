<?php
/**
 * Old product URLs after a combine, against a real WordPress + WooCommerce with the plugin active:
 *   wp eval-file tests/integration-redirect.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_combined_url' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$tag = 'VCR' . wp_rand( 1000, 9999 );
$ids = array();
$parent_id = 0;
try {
	foreach ( array( 'Sea Salt', 'Cedar & Sage' ) as $i => $label ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'VC Redirect Candle ' . $label );
		$p->set_status( 'publish' );
		$p->set_sku( $tag . '-' . $i );
		$p->set_regular_price( '18' );
		$ids[ $p->save() ] = $label;
	}
	$unrelated = array_key_first( $ids );
	check( varconv_combined_url( $unrelated ), '', 'no redirect before a combine' );

	$parent_id = varconv_create( 'VC Redirect Candle', 'Scent', $ids );
	$base = (string) get_permalink( $parent_id );
	$keys = array_keys( $ids );
	$sep = str_contains( $base, '?' ) ? '&' : '?';
	check( varconv_combined_url( $keys[0] ), $base . $sep . 'attribute_scent=Sea%20Salt', 'first member goes to its variant URL, space as %20' );
	check( varconv_combined_url( $keys[1] ), $base . $sep . 'attribute_scent=Cedar%20%26%20Sage', 'second member goes to its variant URL' );

	// Found by the twin link, not the SKU, so a changed or missing SKU still finds the variant.
	update_post_meta( $keys[1], '_sku', '' );
	check( varconv_combined_url( $keys[1] ), $base . $sep . 'attribute_scent=Cedar%20%26%20Sage', 'variant found without a SKU' );

	// A disabled variation, or one with an "Any" attribute, names no single variant: the variable product instead.
	$twins = array_flip( varconv_twins( $parent_id, $keys ) );
	$variation = wc_get_product( $twins[ $keys[0] ] );
	$variation->set_status( 'private' );
	$variation->save();
	check( varconv_combined_url( $keys[0] ), $base, 'disabled variation goes to the variable product' );
	$variation->set_status( 'publish' );
	$variation->set_attributes( array( 'scent' => '' ) );
	$variation->save();
	check( varconv_combined_url( $keys[0] ), $base, '"Any" variation goes to the variable product' );
	$variation->set_attributes( array( 'scent' => 'Sea Salt' ) );
	$variation->save();
	check( varconv_combined_url( $keys[0] ), $base . $sep . 'attribute_scent=Sea%20Salt', 'variant URL back once enabled' );

	add_filter( 'varconv_redirect_url', '__return_empty_string' );
	check( varconv_combined_url( $keys[0] ), '', 'filter turns it off' );
	remove_filter( 'varconv_redirect_url', '__return_empty_string' );

	wp_update_post( array( 'ID' => $parent_id, 'post_status' => 'draft' ) );
	check( varconv_combined_url( $keys[0] ), '', 'no redirect to an unpublished product' );
	wp_update_post( array( 'ID' => $parent_id, 'post_status' => 'publish' ) );

	varconv_undo( $parent_id );
	$parent_id = 0;
	check( varconv_combined_url( $keys[0] ), '', 'Undo stops the redirect' );
	echo "Redirect integration tests passed.\n";
} finally {
	if ( $parent_id ) {
		varconv_delete( $parent_id );
	}
	foreach ( array_keys( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
}
