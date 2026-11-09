<?php
/**
 * Fills History with 30 combines of two simple products each, for tests/browser-history-select-all.mjs:
 *   harness/wp-harness eval tests/seed-history.php
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_create' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}
for ( $n = 1; $n <= 30; $n++ ) {
	$ids = array();
	foreach ( array( 'Red', 'Blue' ) as $i => $label ) {
		$p = new WC_Product_Simple();
		$p->set_name( "VC History Mug $n $label" );
		$p->set_status( 'publish' );
		$p->set_sku( "VCH-$n-$i" );
		$p->set_regular_price( '12' );
		$ids[ $p->save() ] = $label;
	}
	varconv_create( "VC History Mug $n", 'Color', $ids );
}
echo "History seeded with 30 combines.\n";
