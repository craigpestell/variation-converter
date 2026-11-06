<?php
/** Offline checks for the Quick Edit variant stock helpers (includes/admin-quick-edit.php): php tests/test-quick-edit.php */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/admin-quick-edit.php';

function expect( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . json_encode( array( 'expected' => $expected, 'actual' => $actual ), JSON_UNESCAPED_SLASHES ) );
	}
}

$row = static fn( bool $manage, $qty, string $status ) => array( 'manage' => $manage, 'qty' => $qty, 'status' => $status );

// Summary: every variant keeps its own stock.
$s = varconv_variant_stock_summary( array( $row( true, 4, 'instock' ), $row( true, 0, 'outofstock' ) ) );
expect( $s['all_own'], true, 'all own' );
expect( $s['total'], 4, 'total' );
expect( $s['status'], 'instock', 'in stock if any variant is' );
expect( count( $s['own'] ), 2, 'own rows' );

// One variant shares the variable product's count.
$s = varconv_variant_stock_summary( array( $row( true, 3, 'outofstock' ), $row( false, null, 'onbackorder' ) ) );
expect( $s['all_own'], false, 'mixed' );
expect( count( $s['own'] ), 1, 'only own-stock variants listed' );
expect( $s['total'], 3, 'shared count left out' );
expect( $s['status'], 'onbackorder', 'backorder beats out of stock' );

$s = varconv_variant_stock_summary( array( $row( true, null, 'outofstock' ) ) );
expect( array( $s['status'], $s['total'] ), array( 'outofstock', 0 ), 'none in stock, no quantity' );
expect( varconv_variant_stock_summary( array() )['all_own'], false, 'no variations' );

// Changes: only numbers that differ from what the form showed.
expect( varconv_variant_stock_changes( array( 11 => '5', 12 => '3' ), array( 11 => '4', 12 => '3' ) ), array( 11 => 5.0 ), 'unchanged count not written' );
expect( varconv_variant_stock_changes( array( 11 => '3.0' ), array( 11 => '3' ) ), array(), 'same number, other spelling' );
expect( varconv_variant_stock_changes( array( 11 => '0' ), array( 11 => '' ) ), array( 11 => 0.0 ), 'first count, zero' );
expect( varconv_variant_stock_changes( array( 11 => '' ), array( 11 => '4' ) ), array(), 'emptied box ignored' );
expect( varconv_variant_stock_changes( array( 11 => 'ten' ), array( 11 => '4' ) ), array(), 'not a number' );
expect( varconv_variant_stock_changes( array( 11 => '7' ), array() ), array(), 'not shown in the form' );
expect( varconv_variant_stock_changes( array( 0 => '7', -3 => '2' ), array( 0 => '1', -3 => '1' ) ), array(), 'bad IDs' );
expect( varconv_variant_stock_changes( array( 11 => ' -2 ' ), array( 11 => '1' ) ), array( 11 => -2.0 ), 'negative stock allowed, as in WooCommerce' );

echo "Quick Edit tests passed.\n";
