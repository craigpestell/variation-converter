<?php
/**
 * Set one name against a real WordPress + WooCommerce, with the plugin active:
 *   wp eval-file tests/integration-rename.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_renamed' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}
// The admin screens' code, which wp eval-file does not load.
require_once dirname( __DIR__ ) . '/includes/admin.php';

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 ) );
$made = array();
try {
	foreach ( array( 'Rename Test Cherry Lube 4oz' => 'RNT-1', 'Rename Test Lube Cherry 8oz' => 'RNT-2' ) as $title => $sku ) {
		$p = new WC_Product_Simple();
		$p->set_name( $title );
		$p->set_sku( $sku );
		$p->set_regular_price( '10' );
		$p->set_status( 'publish' );
		$made[] = $p->save();
	}
	$variable = new WC_Product_Variable();
	$variable->set_name( 'Rename Test Variable' );
	$made[] = $variable->save();

	$found = varconv_renamable( $made );
	check( array_keys( $found['titles'] ), array_slice( $made, 0, 2 ), 'only simple products can be renamed' );
	check( $found['refused'], array( $made[2] ), 'a variable product is refused' );
	$start = varconv_rename_start( $found['titles'] );
	check( $start['name'], 'Rename Test', 'the shared start is the suggested name' );

	$done = varconv_rename( $made, 'Rename Test Cherry Lube', array( $made[0] => '4 oz', $made[1] => '8 oz', $made[2] => 'x' ) );
	check( $done, array_slice( $made, 0, 2 ), 'both simple products renamed' );
	check( array( wc_get_product( $made[0] )->get_name(), wc_get_product( $made[1] )->get_name() ), array( 'Rename Test Cherry Lube 4 oz', 'Rename Test Cherry Lube 8 oz' ), 'new titles' );
	check( wc_get_product( $made[2] )->get_name(), 'Rename Test Variable', 'variable product left alone' );
	check( (string) get_post_meta( $made[1], VARCONV_OLD_TITLE_META, true ), 'Rename Test Lube Cherry 8oz', 'old title kept' );
	check( varconv_rename( $made, 'Rename Test Cherry Lube', array( $made[0] => '4 oz', $made[1] => '8 oz' ) ), array(), 'same titles again change nothing' );

	check( varconv_rename_undo( $made ), 2, 'undo puts both back' );
	check( array( wc_get_product( $made[0] )->get_name(), wc_get_product( $made[1] )->get_name() ), array( 'Rename Test Cherry Lube 4oz', 'Rename Test Lube Cherry 8oz' ), 'old titles back' );
	check( (string) get_post_meta( $made[0], VARCONV_OLD_TITLE_META, true ), '', 'undo clears the kept title' );
	check( varconv_rename_undo( $made ), 0, 'nothing left to undo' );
} finally {
	foreach ( $made as $id ) {
		wp_delete_post( $id, true );
	}
}
echo "Rename integration tests passed.\n";
