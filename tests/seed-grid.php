<?php
/**
 * Two groups that are one product in two attributes (flavor and size), for tests/browser-grid.mjs:
 *   harness/wp-harness eval tests/seed-grid.php
 * Barista Syrup Vanilla comes in 250ml and 750ml, Caramel in 250ml, 750ml and 1L. Run it on a test site.
 */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Product_Simple' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce is active.\n" );
	exit( 1 );
}

foreach ( array( 'Vanilla' => 'VA', 'Caramel' => 'CA' ) as $flavor => $code ) {
	foreach ( array( '250ml' => array( '02', 9 ), '750ml' => array( '07', 19 ), '1L' => array( '10', 24 ) ) as $size => $info ) {
		$sku = "SYR-$code-" . $info[0];
		if ( ( 'Vanilla' === $flavor && '1L' === $size ) || wc_get_product_id_by_sku( $sku ) ) {
			continue;
		}
		$p = new WC_Product_Simple();
		$p->set_name( "Barista Syrup $flavor $size" );
		$p->set_status( 'publish' );
		$p->set_sku( $sku );
		$p->set_regular_price( (string) $info[1] );
		$p->save();
	}
}
echo "Grid demo products added.\n";
