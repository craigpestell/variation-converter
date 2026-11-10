<?php
/**
 * Add to a combined product, then Make simple and Undo, against a real WordPress + WooCommerce:
 *   wp eval-file tests/integration-add-to.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_add_to' ) ) {
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

$tag = 'VCA' . wp_rand( 1000, 9999 );
$cleanup = array();
$parent_id = 0;
try {
	$simple = static function ( string $name, string $sku, string $price, string $status = 'publish' ) use ( &$cleanup ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( $status );
		$p->set_sku( $sku );
		$p->set_regular_price( $price );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 4 );
		return $cleanup[] = $p->save();
	};
	$black = $simple( 'VC Add Tee Black', $tag . '-BLK', '20' );
	$blue = $simple( 'VC Add Tee Blue', $tag . '-BLU', '20' );
	// The products the scan never groups with them: another SKU, and none at all.
	$green = $simple( 'VC Add Tee Green', 'OTHER' . $tag, '22' );
	$navy = $simple( 'VC Add Tee Navy', '', '23', 'draft' );
	$parent_id = varconv_create( 'VC Add Tee', 'Color', array( $black => 'Black', $blue => 'Blue' ) );

	throws( static fn() => varconv_add_to( $green, array( $navy => array( 'Navy' ) ) ), 'only be added to a variable product', 'not a combined product' );
	throws( static fn() => varconv_add_to( $parent_id, array( $green => array( 'blue' ) ) ), 'already a "blue" variation', 'a label taken' );
	throws( static fn() => varconv_add_to( $parent_id, array( $black => array( 'Red' ) ) ), 'already in a combined product', 'a member again' );
	check( count( wc_get_product( $parent_id )->get_children() ), 2, 'nothing changed after a refusal' );

	$made = varconv_add_to( $parent_id, array( $green => array( 'Green' ), $navy => array( 'Navy' ) ) );
	check( count( $made ), 2, 'two variations' );
	$parent = wc_get_product( $parent_id );
	check( $parent->get_attributes()['color']->get_options(), array( 'Black', 'Blue', 'Green', 'Navy' ), 'options added' );
	check( '' === $parent->get_sku( 'edit' ), true, 'still no parent SKU' );
	$v = wc_get_product( $made[0] );
	check( $v->get_sku(), 'OTHER' . $tag, 'variation keeps its SKU' );
	check( $v->get_regular_price(), '22', 'price' );
	check( $v->get_stock_quantity(), 4, 'stock' );
	check( $v->get_status(), 'publish', 'published product: enabled variation' );
	check( $v->get_attributes(), array( 'color' => 'Green' ), 'label' );
	check( wc_get_product( $made[1] )->get_status(), 'private', 'draft product: disabled variation' );
	check( wc_get_product( $made[1] )->get_sku(), '', 'no SKU is fine' );
	check( wc_get_product( $green )->get_catalog_visibility(), 'hidden', 'added product hidden' );
	check( (int) get_post_meta( $green, VARCONV_PARENT_META, true ), $parent_id, 'linked to the combined product' );
	check( in_array( $green, json_decode( get_post_meta( $parent_id, VARCONV_META, true ), true ), true ), true, 'in the combine list' );
	check( str_contains( varconv_combined_url( $green ), 'attribute_color=Green' ), true, 'old page redirects to its variant' );

	// Make simple takes just the added one back out.
	varconv_take_out( $parent_id, array( $made[0] => '' ) );
	check( wc_get_product( $green )->get_catalog_visibility(), 'visible', 'Make simple shows it again' );
	check( (string) get_post_meta( $green, VARCONV_PARENT_META, true ), '', 'and unlinks it' );
	varconv_take_out_undo( $green );
	check( (int) get_post_meta( $green, VARCONV_PARENT_META, true ), $parent_id, 'its Undo puts it back' );

	// Undo of the combine gives every product back, the added ones too.
	check( varconv_undo( $parent_id ), 4, 'four restored' );
	$parent_id = 0;
	foreach ( array( $black, $blue, $green, $navy ) as $id ) {
		check( wc_get_product( $id )->get_catalog_visibility(), 'visible', "product $id visible again" );
	}
	echo "Add to a combined product integration tests passed.\n";
} finally {
	if ( $parent_id ) {
		varconv_delete( $parent_id );
	}
	foreach ( $cleanup as $id ) {
		wp_delete_post( $id, true );
	}
}
