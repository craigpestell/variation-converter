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

	$parent_id = varconv_create( 'VC Redirect Candle', 'Scent', '', $ids );
	$base = (string) get_permalink( $parent_id );
	$keys = array_keys( $ids );
	check( varconv_combined_url( $keys[0] ), add_query_arg( 'attribute_scent', rawurlencode( 'Sea Salt' ), $base ), 'first member goes to its option' );
	check( varconv_combined_url( $keys[1] ), add_query_arg( 'attribute_scent', rawurlencode( 'Cedar & Sage' ), $base ), 'second member goes to its option' );

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
