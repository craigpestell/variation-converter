<?php
/**
 * Private, pending and draft products on every tab, and the status of what Split and Combine make, against a real
 * WordPress + WooCommerce with the plugin active:
 *   wp eval-file tests/integration-status.php
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

$cleanup = array();
$tag = 'VCS' . wp_rand( 1000, 9999 );
try {
	// Split: a private variable product is listed, and splitting it makes private products.
	$size = new WC_Product_Attribute();
	$size->set_name( 'Size' );
	$size->set_options( array( 'Small', 'Large' ) );
	$size->set_variation( true );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'VC Status Hoodie' );
	$parent->set_status( 'private' );
	$parent->set_attributes( array( $size ) );
	$parent_id = $parent->save();
	$cleanup[] = $parent_id;
	$variations = array();
	foreach ( array( 'Small', 'Large' ) as $i => $s ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_attributes( array( 'size' => $s ) );
		$v->set_status( 'publish' );
		$v->set_sku( $tag . '-H' . $i );
		$v->set_regular_price( '40' );
		$variations[ $v->save() ] = 'VC Status Hoodie ' . $s;
	}
	WC_Product_Variable::sync( $parent_id );
	check( in_array( $parent_id, varconv_variables( '', 1, 100 )['ids'], true ), true, 'private variable product on the Split tab' );
	check( in_array( $parent_id, varconv_variables( 'VC Status Hoodie', 1, 100 )['ids'], true ), true, 'private variable product found by search' );
	$made = varconv_split_product( $parent_id, $variations );
	$cleanup = array_merge( $cleanup, $made );
	check( array_map( static fn( $id ) => get_post_status( $id ), $made ), array( 'private', 'private' ), 'split of a private product makes private products' );
	check( in_array( $parent_id, array_column( varconv_history(), 'id' ), true ), true, 'private split in History' );
	varconv_split_undo( $parent_id );
	$cleanup = array_diff( $cleanup, $made );

	// Combine: private and draft simple products are candidates.
	$simples = array();
	foreach ( array( 'Red' => 'private', 'Blue' => 'private', 'Green' => 'draft' ) as $label => $status ) {
		$s = new WC_Product_Simple();
		$s->set_name( 'VC Status Cap ' . $label );
		$s->set_status( $status );
		$s->set_sku( $tag . '-C-' . strtoupper( $label ) );
		$s->set_regular_price( '15' );
		$simples[ $s->save() ] = $label;
	}
	$cleanup = array_merge( $cleanup, array_keys( $simples ) );
	$found = varconv_products_by_id( array_keys( $simples ) );
	check( $found['refused'], array(), 'private and draft products can be combined' );
	check( array_column( $found['products'], 'status' ), array( 'private', 'private', 'draft' ), 'statuses in the scan' );

	// All private: a private product, every variation enabled.
	$private_two = array_slice( $simples, 0, 2, true );
	$combined = varconv_create( 'VC Status Cap', 'Color', $private_two );
	$cleanup[] = $combined;
	check( get_post_status( $combined ), 'private', 'combining private products makes a private product' );
	check( array_map( static fn( $id ) => get_post_status( $id ), wc_get_product( $combined )->get_children() ), array( 'publish', 'publish' ), 'variations enabled' );
	varconv_undo( $combined );
	$cleanup = array_diff( $cleanup, array( $combined ) );
	check( array_map( static fn( $id ) => get_post_status( $id ), array_keys( $private_two ) ), array( 'private', 'private' ), 'Undo leaves members private' );

	// Mixed: as visible as the most visible member; the draft member's variation is disabled, so it is not on sale.
	$combined = varconv_create( 'VC Status Cap', 'Color', $simples );
	$cleanup[] = $combined;
	check( get_post_status( $combined ), 'private', 'private beats draft' );
	check( array_map( static fn( $id ) => get_post_status( $id ), wc_get_product( $combined )->get_children() ), array( 'publish', 'publish', 'private' ), 'draft member disabled' );

	echo "Status integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $id ) {
		if ( '' !== (string) get_post_meta( $id, VARCONV_META, true ) ) {
			varconv_undo( $id );
			continue;
		}
		$product = wc_get_product( $id );
		foreach ( $product ? $product->get_children() : array() as $child ) {
			wp_delete_post( $child, true );
		}
		wp_delete_post( $id, true );
	}
}
