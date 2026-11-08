<?php
/**
 * Make simple (one variation out, the rest stays variable) and its Undo against a real WordPress + WooCommerce:
 *   wp eval-file tests/integration-take-out.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_take_out' ) ) {
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

$cleanup = array();
$tag = 'vctake' . wp_rand( 1000, 9999 );
try {
	// 1. A variable product made some other way, with three sizes.
	$size = new WC_Product_Attribute();
	$size->set_name( 'Size' );
	$size->set_options( array( 'Small', 'Medium', 'Large' ) );
	$size->set_variation( true );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'VC Take Shirt' );
	$parent->set_status( 'publish' );
	$parent->set_description( 'Shirt description' );
	$parent->set_attributes( array( $size ) );
	$parent_id = $cleanup[] = $parent->save();
	$variations = array();
	foreach ( array( 'Small', 'Medium', 'Large' ) as $i => $label ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_attributes( array( 'size' => $label ) );
		$v->set_sku( strtoupper( $tag ) . '-' . $i );
		$v->set_regular_price( (string) ( 20 + $i ) );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 5 + $i );
		$v->set_status( 'publish' );
		$variations[ $label ] = $cleanup[] = $v->save();
	}
	WC_Product_Variable::sync( $parent_id );

	$made = varconv_take_out( $parent_id, array( $variations['Medium'] => '' ) );
	check( count( $made ), 1, 'one simple product' );
	$simple = wc_get_product( $made[0] );
	$cleanup[] = $simple->get_id();
	check( $simple->get_type(), 'simple', 'is simple' );
	check( $simple->get_name(), 'VC Take Shirt Medium', 'default name' );
	check( $simple->get_sku(), strtoupper( $tag ) . '-1', 'keeps the variation SKU' );
	check( $simple->get_regular_price(), '21', 'keeps the price' );
	check( (int) $simple->get_stock_quantity(), 6, 'keeps the stock' );
	check( $simple->get_status(), 'publish', 'variable product status' );
	check( $simple->get_description(), 'Shirt description', 'description copied' );
	check( get_post_status( $variations['Medium'] ), 'private', 'variation disabled' );
	check( varconv_taken_simple( $variations['Medium'] ), $simple->get_id(), 'link found' );
	check( wc_get_product( $parent_id )->get_type(), 'variable', 'still variable' );
	check( count( wc_get_product( $parent_id )->get_visible_children() ), 2, 'two variations for sale' );
	check( in_array( $simple->get_id(), array_column( varconv_history(), 'id' ), true ), true, 'in History' );
	check( in_array( $simple->get_id(), array_column( varconv_products(), 'id' ), true ), false, 'not offered to Combine' );

	// The simple product and the disabled variation share a SKU; either still saves.
	$simple->set_regular_price( '30' );
	$simple->set_stock_quantity( 2 );
	$simple->save();
	check( wc_get_product( $simple->get_id() )->get_regular_price(), '30', 'simple product saves with the shared SKU' );

	throws( fn() => varconv_take_out( $parent_id, array( $variations['Medium'] => '' ) ), 'already a simple product', 'twice refused' );

	// The last variations for sale: the variable product goes to the Trash, its old URLs go to the simple products.
	$slug = get_post_field( 'post_name', $parent_id );
	$removed = array();
	add_action( 'varconv_made_simple_removed', static function ( $id ) use ( &$removed ) {
		$removed[] = $id;
	} );
	$rest = varconv_take_out( $parent_id, array( $variations['Small'] => '', $variations['Large'] => '' ) );
	$cleanup = array_merge( $cleanup, $rest );
	check( count( $rest ), 2, 'the last two made simple' );
	check( get_post_status( $parent_id ), 'trash', 'variable product in the Trash' );
	check( $removed, array( $parent_id ), 'removed hook fired' );
	check( varconv_taken_all( $parent_id ), true, 'marked as removed' );
	check( get_post_status( $variations['Small'] ), 'trash', 'its variations in the Trash too' );
	check( get_post_status( $rest[0] ), 'publish', 'simple products published' );
	check( count( array_intersect( array( $simple->get_id(), $rest[0], $rest[1] ), array_column( varconv_history(), 'id' ) ) ), 3, 'all three in History' );
	check( varconv_removed_url( $slug, array() ), (string) get_permalink( $rest[0] ), 'old URL goes to the first simple product' );
	check( varconv_removed_url( $slug, array( 'attribute_size' => 'Medium' ) ), (string) get_permalink( $simple->get_id() ), 'old variant URL goes to its simple product' );
	check( varconv_removed_url( 'no-such-' . $tag, array() ), '', 'other slugs left alone' );
	check( varconv_take_out_problem( wc_get_product( $variations['Medium'] ) ) !== '', true, 'nothing more to make simple' );

	// Undo of one of them restores it from the Trash, with the others still disabled.
	varconv_take_out_undo( $rest[1] );
	check( get_post_status( $parent_id ), 'publish', 'variable product restored' );
	check( get_post_field( 'post_name', $parent_id ), $slug, 'slug back' );
	check( varconv_taken_all( $parent_id ), false, 'marker cleared' );
	check( get_post_status( $variations['Large'] ), 'publish', 'undone variation enabled' );
	check( get_post_status( $variations['Small'] ), 'private', 'still made simple: disabled' );
	check( wc_get_product( $variations['Small'] )->get_sku(), strtoupper( $tag ) . '-0', 'restored variation keeps its SKU' );
	check( varconv_removed_url( $slug, array() ), '', 'no redirect once restored' );
	varconv_take_out_undo( $rest[0] );
	check( get_post_status( $variations['Small'] ), 'publish', 'second undo enables it' );
	throws( fn() => varconv_split_product( $parent_id, array( $variations['Medium'] => 'X' ) ), 'already a simple product', 'Split refuses it' );

	// Undo: the variation is enabled again with the simple product's current numbers, and the simple product deleted.
	check( varconv_take_out_undo( $simple->get_id() ), $variations['Medium'], 'undo returns the variation' );
	check( get_post( $simple->get_id() ), null, 'simple product deleted' );
	$v = wc_get_product( $variations['Medium'] );
	check( $v->get_status(), 'publish', 'variation enabled again' );
	check( $v->get_regular_price(), '30', 'current price back' );
	check( (int) $v->get_stock_quantity(), 2, 'current stock back' );
	check( varconv_taken_simple( $variations['Medium'] ), 0, 'link gone' );

	// The old variant URL sends shoppers to the simple product, until Undo.
	$made = varconv_take_out( $parent_id, array( $variations['Large'] => 'Big Shirt' ) );
	$cleanup[] = $made[0];
	check( wc_get_product( $made[0] )->get_name(), 'Big Shirt', 'name given' );
	check( varconv_made_simple_url( $parent_id, array( 'ATTRIBUTE_SIZE' => 'large', 'utm_source' => 'x' ) ), (string) get_permalink( $made[0] ), 'variant URL redirects, any case, other values ignored' );
	check( varconv_made_simple_url( $parent_id, array( 'attribute_size' => 'Small' ) ), '', 'a live variant does not redirect' );
	check( varconv_made_simple_url( $parent_id, array() ), '', 'plain URL does not redirect' );
	varconv_take_out_undo( $made[0] );
	check( varconv_made_simple_url( $parent_id, array( 'attribute_size' => 'Large' ) ), '', 'no redirect after Undo' );

	// A $0 price stays a price; a variation on the variable product's stock count copies no count.
	$p = wc_get_product( $parent_id );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 50 );
	$p->save();
	$small = wc_get_product( $variations['Small'] );
	$small->set_manage_stock( false );
	$small->set_regular_price( '0' );
	$small->save();
	$made = varconv_take_out( $parent_id, array( $variations['Small'] => '' ) );
	$cleanup[] = $made[0];
	$s = wc_get_product( $made[0] );
	check( $s->get_regular_price(), '0', '$0 price kept' );
	check( $s->is_purchasable(), true, 'purchasable at $0' );
	check( $s->get_manage_stock(), false, 'shared stock count not copied' );
	varconv_take_out_undo( $made[0] );

	// 2. A product Combine made: the original simple product comes back and leaves it.
	$cat_a = wp_insert_term( 'VC Take A ' . $tag, 'product_cat' )['term_id'];
	$cat_b = wp_insert_term( 'VC Take B ' . $tag, 'product_cat' )['term_id'];
	$members = array();
	foreach ( array( 'Red', 'Blue', 'Green' ) as $i => $label ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'VC Take Mug ' . $label );
		$p->set_status( 'publish' );
		$p->set_sku( strtoupper( $tag ) . '-M' . $i );
		$p->set_regular_price( '12' );
		$p->set_category_ids( array( $cat_a ) );
		$members[ $cleanup[] = $p->save() ] = $label;
	}
	$keys = array_keys( $members );
	$combined = $cleanup[] = varconv_create( 'VC Take Mug', 'Color', $members );
	$twins = array_flip( varconv_twins( $combined, $keys ) );
	$blue_variation = $twins[ $keys[1] ];
	$v = wc_get_product( $blue_variation );
	$v->set_regular_price( '14' );
	$v->set_description( 'Edited on the variation' );
	$v->save();
	$c = wc_get_product( $combined );
	$c->set_default_attributes( array( 'color' => 'Blue' ) );
	$c->save();
	check( varconv_combined_url( $keys[1] ) !== '', true, 'combined member redirects' );
	check( varconv_take_out_categories( wc_get_product( $blue_variation ) ), array( $cat_a ), 'categories prefilled from the original' );

	$fired = array();
	$hook = static function ( ...$args ) use ( &$fired ) {
		$fired = array_merge( $args, array( get_post_status( $args[1] ) ) );
	};
	add_action( 'varconv_made_simple', $hook, 10, 4 );
	$made = varconv_take_out( $combined, array( $blue_variation => '' ), array( $blue_variation => array( $cat_b ) ) );
	remove_action( 'varconv_made_simple', $hook, 10 );
	check( $made, array( $keys[1] ), 'the original product comes back' );
	check( $fired, array( $keys[1], $blue_variation, $combined, true, 'publish' ), 'hook fires while the variation is enabled' );
	$blue = wc_get_product( $keys[1] );
	check( $blue->get_catalog_visibility(), 'visible', 'shown again' );
	check( $blue->get_name(), 'VC Take Mug Blue', 'keeps its own name' );
	check( $blue->get_regular_price(), '14', 'takes the variation price' );
	check( $blue->get_description(), 'Edited on the variation', 'takes the variation description' );
	check( $blue->get_category_ids(), array( $cat_b ), 'chosen category' );
	check( $blue->get_meta( VARCONV_PARENT_META ), '', 'unlinked from the combined product' );
	check( in_array( $keys[1], varconv_members( $combined ), true ), false, 'not a member any more' );
	check( in_array( $keys[1], array_map( 'intval', json_decode( get_post_meta( $combined, VARCONV_META, true ), true ) ), true ), false, 'out of the collection list' );
	check( get_post_status( $blue_variation ), 'private', 'combined variation disabled' );
	check( varconv_combined_url( $keys[1] ), '', 'its own page no longer redirects' );
	check( varconv_made_simple_url( $combined, array( 'attribute_color' => 'Blue' ) ), (string) get_permalink( $keys[1] ), 'old variant URL goes to it' );
	check( wc_get_product( $combined )->get_default_attributes(), array(), 'default that named it cleared' );
	check( count( wc_get_product( $combined )->get_visible_children() ), 2, 'rest stays variable' );
	$blue->set_regular_price( '15' );
	$blue->save();
	check( wc_get_product( $keys[1] )->get_regular_price(), '15', 'saves with the shared SKU' );

	// Undo puts it back in the combined product, hidden.
	varconv_take_out_undo( $keys[1] );
	check( wc_get_product( $keys[1] )->get_catalog_visibility(), 'hidden', 'hidden again' );
	check( (int) get_post_meta( $keys[1], VARCONV_PARENT_META, true ), $combined, 'linked again' );
	check( in_array( $keys[1], varconv_members( $combined ), true ), true, 'a member again' );
	check( get_post_status( $blue_variation ), 'publish', 'variation enabled' );
	check( wc_get_product( $blue_variation )->get_regular_price(), '15', 'variation takes the current price' );
	check( wc_get_product( $combined )->get_default_attributes(), array( 'color' => 'Blue' ), 'default back' );
	check( varconv_combined_url( $keys[1] ) !== '', true, 'redirect back' );

	// Every variation of a combined product: all three originals come back and the combined product is in the Trash.
	$all = varconv_take_out( $combined, array_fill_keys( array_values( $twins ), '' ) );
	check( count( $all ), 3, 'three originals back' );
	check( get_post_status( $combined ), 'trash', 'combined product in the Trash' );
	check( in_array( $combined, varconv_created(), true ), false, 'combined row leaves History' );
	check( wc_get_product( $keys[0] )->get_catalog_visibility(), 'visible', 'originals shown' );
	$undo = varconv_undo_many( $all );
	check( $undo['errors'], array(), 'Undo all of them' );
	check( get_post_status( $combined ), 'publish', 'combined product back' );
	check( count( varconv_members( $combined ) ), 3, 'all members again' );
	check( count( wc_get_product( $combined )->get_visible_children() ), 3, 'all for sale again' );
	check( in_array( $combined, varconv_created(), true ), true, 'combined row back in History' );

	// Made simple, then the whole combine undone: Undo brings back the other two and leaves this one as it is.
	varconv_take_out( $combined, array( $blue_variation => '' ) );
	$blue = wc_get_product( $keys[1] );
	$blue->set_regular_price( '16' );
	$blue->save();
	check( varconv_undo( $combined ), 2, 'combine Undo restores the other two' );
	$blue = wc_get_product( $keys[1] );
	check( $blue->get_regular_price(), '16', 'made-simple product keeps its numbers' );
	check( $blue->get_meta( VARCONV_TAKEN_META ), '', 'link cleared' );
	check( $blue->get_catalog_visibility(), 'visible', 'still visible' );
	check( in_array( $keys[1], array_column( varconv_history(), 'id' ), true ), false, 'gone from History' );

	// A removed variable product emptied from the Trash: its simple products stay, out of History.
	$last = varconv_take_out( $parent_id, array_fill_keys( array_values( $variations ), '' ) );
	$cleanup = array_merge( $cleanup, $last );
	check( get_post_status( $parent_id ), 'trash', 'trashed again' );
	wp_delete_post( $parent_id, true );
	check( get_post_status( $last[0] ), 'publish', 'simple product stays' );
	check( get_post_meta( $last[0], VARCONV_TAKEN_META, true ), '', 'link forgotten' );
	check( in_array( $last[0], array_column( varconv_history(), 'id' ), true ), false, 'out of History' );

	echo "Make simple integration tests passed.\n";
} finally {
	foreach ( array_reverse( $cleanup ) as $id ) {
		if ( get_post( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	foreach ( array( $cat_a ?? 0, $cat_b ?? 0 ) as $term ) {
		if ( $term ) {
			wp_delete_term( $term, 'product_cat' );
		}
	}
}
