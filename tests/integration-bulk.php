<?php
/**
 * The bulk run's pieces against a real WordPress + WooCommerce, with the plugin active:
 *   wp eval-file tests/integration-bulk.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_convert' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

$cleanup = array();
$terms = array();
$tag = 'VCB' . wp_rand( 1000, 9999 );
$excluded_before = get_option( VARCONV_EXCLUDED_OPTION, null );
try {
	// The lock: one holder at a time; an expired one can be taken over.
	varconv_unlock();
	check( varconv_lock(), true, 'lock taken' );
	check( varconv_lock(), false, 'second lock refused' );
	varconv_lock_renew( -10 );
	check( varconv_lock(), true, 'expired lock taken over' );
	varconv_unlock();
	check( varconv_lock(), true, 'lock taken after unlock' );
	varconv_unlock();

	$cat_a = wp_insert_term( 'VC Bulk A ' . $tag, 'product_cat' )['term_id'];
	$cat_b = wp_insert_term( 'VC Bulk B ' . $tag, 'product_cat' )['term_id'];
	$cat_c = wp_insert_term( 'VC Bulk C ' . $tag, 'product_cat' )['term_id'];
	$terms = array( $cat_a, $cat_b, $cat_c );
	$make = static function ( string $sku, string $name, array $cats ) use ( &$cleanup ) {
		$s = new WC_Product_Simple();
		$s->set_name( $name );
		$s->set_status( 'publish' );
		$s->set_sku( $sku );
		$s->set_regular_price( '5' );
		$s->set_category_ids( $cats );
		return $cleanup[] = $s->save();
	};
	$red = $make( $tag . '-01', $tag . ' Mug Red', array( $cat_a ) );
	$blue = $make( $tag . '-02', $tag . ' Mug Blue', array( $cat_b ) );
	$green = $make( $tag . '-03', $tag . ' Mug Green', array( $cat_b ) );

	// Categories chosen for the new product must be the members'.
	try {
		varconv_create( $tag . ' Mug', 'Color', array( $red => 'Red', $blue => 'Blue' ), array( $cat_c ) );
		throw new LogicException( 'foreign category accepted' );
	} catch ( RuntimeException $e ) {
		check( str_contains( $e->getMessage(), 'category' ), true, 'foreign category refused' );
	}
	$id = $cleanup[] = varconv_create( $tag . ' Mug', 'Color', array( $red => 'Red', $blue => 'Blue' ), array( $cat_b ) );
	check( wc_get_product( $id )->get_category_ids(), array( $cat_b ), 'chosen category' );
	varconv_undo( $id );
	$id = $cleanup[] = varconv_create( $tag . ' Mug', 'Color', array( $red => 'Red', $blue => 'Blue' ) );
	check( wc_get_product( $id )->get_category_ids(), array( $cat_a ), 'first member\'s category by default' );
	varconv_undo( $id );

	// The scan finds the group; unchecking a member is remembered and bulk runs leave it out.
	$find = static fn() => array_values( array_filter( varconv_bulk_candidates(), static fn( $c ) => $c['sku'] === $tag ) );
	varconv_candidates( true );
	$group = $find();
	check( count( $group ), 1, 'group found' );
	check( count( $group[0]['members'] ), 3, 'three members' );
	varconv_set_excluded( $group[0]['key'], array( $green ), true );
	check( array_column( $find()[0]['members'], 'id' ), array( $red, $blue ), 'unchecked member left out' );
	varconv_set_excluded( $group[0]['key'], array( $green ), false );
	check( count( $find()[0]['members'] ), 3, 'checked again' );
	check( isset( varconv_excluded()[ $group[0]['key'] ] ), false, 'empty entry removed' );

	// Converting: dry run, then for real. The variable product gets no SKU; its variations keep theirs.
	$c = $find()[0];
	check( varconv_convert( $c, true )['status'], 'would', 'dry run' );
	check( count( $find() ), 1, 'dry run changed nothing' );
	$result = varconv_convert( $c );
	$cleanup[] = $result['id'];
	check( $result['status'], 'made', 'made' );
	check( wc_get_product( $result['id'] )->get_sku( 'edit' ), '', 'no parent SKU' );
	check( count( array_filter( array_map( static fn( $v ) => wc_get_product( $v )->get_sku( 'edit' ), wc_get_product( $result['id'] )->get_children() ) ) ), 3, 'variations keep their SKUs' );
	varconv_undo( $result['id'] );

	// A group needing a label is skipped, not created.
	$c['members'][0]['label'] = '';
	check( varconv_convert( $c )['status'], 'skip', 'missing label skipped' );

	echo "Bulk integration tests passed.\n";
} finally {
	varconv_unlock();
	foreach ( array_reverse( $cleanup ) as $id ) {
		$product = wc_get_product( $id );
		foreach ( $product ? $product->get_children() : array() as $child ) {
			wp_delete_post( $child, true );
		}
		wp_delete_post( $id, true );
	}
	foreach ( $terms as $term ) {
		wp_delete_term( $term, 'product_cat' );
	}
	null === $excluded_before ? delete_option( VARCONV_EXCLUDED_OPTION ) : update_option( VARCONV_EXCLUDED_OPTION, $excluded_before, false );
	delete_transient( VARCONV_SCAN_TRANSIENT );
}
