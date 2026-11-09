<?php
/**
 * Trash originals after a combine, against a real WordPress + WooCommerce with the plugin active:
 *   wp eval-file tests/integration-trash-originals.php
 * Reviews move to the variable product and back, old URLs keep redirecting (also once deleted for good), Undo and
 * Make simple restore originals from the Trash, and Undo refuses once one is deleted for good.
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_trash_originals' ) ) {
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

$tag = 'VCT' . wp_rand( 1000, 9999 );
$cleanup = array();
$parents = array();
try {
	$make = static function ( string $name, array $labels ) use ( $tag, &$cleanup ): array {
		$ids = array();
		foreach ( $labels as $i => $label ) {
			$p = new WC_Product_Simple();
			$p->set_name( $name . ' ' . $label );
			$p->set_status( 'publish' );
			$p->set_sku( $tag . '-' . sanitize_title( $name ) . '-' . $i );
			$p->set_regular_price( '18' );
			$id = $p->save();
			$cleanup[] = $id;
			$ids[ $id ] = $label;
		}
		return $ids;
	};
	$review = static function ( int $product_id, int $rating ): int {
		$id = (int) wp_insert_comment( array(
			'comment_post_ID'  => $product_id,
			'comment_author'   => 'Tester',
			'comment_content'  => 'Nice ' . $rating,
			'comment_type'     => 'review',
			'comment_approved' => 1,
		) );
		update_comment_meta( $id, 'rating', $rating );
		WC_Comments::clear_transients( $product_id );
		return $id;
	};

	// 1. Combine two candles, each with a review.
	$ids = $make( 'VC Trash Candle', array( 'Sea Salt', 'Cedar' ) );
	$keys = array_keys( $ids );
	$r0 = $review( $keys[0], 5 );
	$r1 = $review( $keys[1], 3 );
	$slugs = array_map( static fn( $id ) => (string) get_post_field( 'post_name', $id ), $keys );
	$parent_id = $parents[] = varconv_create( 'VC Trash Candle', 'Scent', $ids );
	$base = (string) get_permalink( $parent_id );
	$sep = str_contains( $base, '?' ) ? '&' : '?';
	check( varconv_originals( $parent_id ), array( 'hidden' => $keys, 'trash' => array(), 'gone' => array() ), 'both originals hidden after combine' );
	check( varconv_trashed_original_url( $slugs[0] ), '', 'no slug redirect before trashing' );

	// 2. Trash originals.
	check( varconv_trash_originals( $parent_id ), 2, 'two moved to the Trash' );
	check( get_post_status( $keys[0] ), 'trash', 'first original in the Trash' );
	check( varconv_originals( $parent_id ), array( 'hidden' => array(), 'trash' => $keys, 'gone' => array() ), 'both in the Trash' );
	check( (int) get_comment( $r0 )->comment_post_ID, $parent_id, 'review moved to the variable product' );
	check( get_comment( $r0 )->comment_approved, '1', 'moved review still approved' );
	check( (int) wc_get_product( $parent_id )->get_review_count(), 2, 'variable product counts both reviews' );
	check( (string) wc_get_product( $parent_id )->get_average_rating(), '4.00', 'variable product averages their ratings' );
	check( varconv_trashed_original_url( $slugs[0] ), $base . $sep . 'attribute_scent=Sea%20Salt', 'old URL goes to its variant URL' );
	check( varconv_trashed_original_url( $slugs[1] ), $base . $sep . 'attribute_scent=Cedar', 'second old URL goes to its variant URL' );
	throws( static fn() => varconv_trash_originals( $parent_id ), 'no hidden originals', 'nothing left to trash' );

	// 3. Undo restores them from the Trash, published, with their reviews and their own pages.
	check( varconv_undo( $parent_id ), 2, 'Undo restores both' );
	$parents = array();
	check( get_post_status( $keys[0] ), 'publish', 'restored with its old status, not draft' );
	check( (string) get_post_field( 'post_name', $keys[0] ), $slugs[0], 'restored with its old slug' );
	check( wc_get_product( $keys[0] )->get_sku(), $tag . '-vc-trash-candle-0', 'restored with its SKU' );
	check( wc_get_product( $keys[0] )->get_catalog_visibility(), 'visible', 'visible again' );
	check( (int) get_comment( $r0 )->comment_post_ID, $keys[0], 'review back on its product' );
	check( (int) get_comment( $r1 )->comment_post_ID, $keys[1], 'second review back' );
	check( get_comment_meta( $r0, VARCONV_REVIEW_FROM_META, true ), '', 'review marker removed' );
	check( (int) wc_get_product( $keys[0] )->get_review_count(), 1, 'its review counts again' );
	check( varconv_trashed_original_url( $slugs[0] ), '', 'no slug redirect after Undo' );

	// 4. Make simple of a variation whose original is in the Trash restores that original.
	$parent_id = $parents[] = varconv_create( 'VC Trash Candle', 'Scent', $ids );
	varconv_trash_originals( $parent_id );
	$twins = array_flip( varconv_twins( $parent_id, $keys ) );
	$made = varconv_take_out( $parent_id, array( $twins[ $keys[1] ] => '' ) );
	check( $made, array( $keys[1] ), 'Make simple reuses the original' );
	check( get_post_status( $keys[1] ), 'publish', 'original out of the Trash' );
	check( (int) get_comment( $r1 )->comment_post_ID, $keys[1], 'its review goes back with it' );
	check( (int) wc_get_product( $parent_id )->get_review_count(), 1, 'variable product keeps the other review' );
	check( varconv_trashed_original_url( $slugs[1] ), '', 'its slug is its own again' );
	varconv_take_out_undo( $keys[1] );
	varconv_undo( $parent_id );
	$parents = array();

	// 4b. Restored from the Trash by hand: its old status, not draft, and its review back.
	$parent_id = $parents[] = varconv_create( 'VC Trash Candle', 'Scent', $ids );
	varconv_trash_originals( $parent_id );
	wp_untrash_post( $keys[0] );
	check( get_post_status( $keys[0] ), 'publish', 'restored by hand keeps its status' );
	check( (int) get_comment( $r0 )->comment_post_ID, $keys[0], 'restored by hand takes its review back' );
	varconv_undo( $parent_id );
	$parents = array();
	check( get_post_status( $keys[0] ), 'publish', 'published after a later Undo' );

	// 5. Deleted for good: the old URL still redirects, and Undo refuses.
	$parent_id = $parents[] = varconv_create( 'VC Trash Candle', 'Scent', $ids );
	varconv_trash_originals( $parent_id );
	wp_delete_post( $keys[0], true );
	check( varconv_originals( $parent_id )['gone'], array( $keys[0] ), 'deleted original reported' );
	check( varconv_trashed_original_url( $slugs[0] ), $base . $sep . 'attribute_scent=Sea%20Salt', 'redirect survives deleting for good' );
	throws( static fn() => varconv_undo( $parent_id ), 'can no longer be undone', 'Undo refuses once an original is gone' );
	check( get_post_status( $keys[1] ), 'trash', 'refused Undo changed nothing' );
	check( 'publish', get_post_status( $parent_id ), 'variable product kept' );
	wp_update_post( array( 'ID' => $parent_id, 'post_status' => 'draft' ) );
	check( varconv_trashed_original_url( $slugs[0] ), '', 'no redirect to an unpublished product' );

	// 6. A product that was not combined.
	throws( static fn() => varconv_trash_originals( $keys[1] ), 'not a variable product combined', 'refuses other products' );

	// 7. Several rows at once, as "select all" sends them: rows with nothing to move are skipped, not errors.
	$fresh = $make( 'VC Trash Soap', array( 'Lime', 'Mint' ) );
	$soap = $parents[] = varconv_create( 'VC Trash Soap', 'Scent', $fresh );
	$result = varconv_trash_originals_many( array( $parent_id, $keys[1], $soap, $soap ) );
	check( $result, array( 'done' => 1, 'products' => 2, 'skipped' => 2, 'ran' => 3, 'errors' => array() ), 'many: one combine done, two skipped' );
	check( varconv_trash_originals_many( array( $soap ) )['skipped'], 1, 'many: nothing left is skipped' );
	check( count( varconv_history_view( '', 'combined' ) ) <= count( varconv_history_view() ), true, 'views are part of All' );
	check( in_array( $soap, array_column( varconv_history_view( 'VC Trash Soap', 'combined' ), 'id' ), true ), true, 'view and search find the combine' );
	check( varconv_history_view( 'VC Trash Soap', 'split' ), array(), 'split view leaves combines out' );

	echo "Trash originals integration tests passed.\n";
} finally {
	foreach ( $parents as $p ) {
		varconv_delete( $p );
	}
	foreach ( $cleanup as $id ) {
		wp_delete_post( $id, true );
	}
}
