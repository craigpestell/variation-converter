<?php
/** Offline checks for Make simple's validation (includes/take-out.php): php tests/test-take-out.php */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/take-out.php';

function expect( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . json_encode( array( 'expected' => $expected, 'actual' => $actual ), JSON_UNESCAPED_SLASHES ) );
	}
}

$children = array( 11, 12, 13 );
expect( varconv_take_out_validate( array( 11 ), $children, $children, array() ), array(), 'one of three' );
expect( varconv_take_out_validate( array( 11, 12 ), $children, $children, array() ), array(), 'two of three' );
expect( varconv_take_out_validate( array(), $children, $children, array() ), array( 'Choose at least 1 variation.' ), 'none chosen' );
expect( varconv_take_out_validate( array( 99 ), $children, $children, array() ), array( 'Product 99 is not a variation of this product.' ), 'not a child' );
expect( varconv_take_out_validate( array( 12 ), $children, array( 11, 13 ), array( 12 ) ), array( 'Variation 12 is already a simple product.' ), 'already made simple' );
$last = 'That would leave no variation for sale. To make every variation a simple product, use Split, or Undo a combined product in History.';
expect( varconv_take_out_validate( $children, $children, $children, array() ), array( $last ), 'all of them' );
expect( varconv_take_out_validate( array( 13 ), $children, array( 13 ), array( 11, 12 ) ), array( $last ), 'the last one for sale' );
// A disabled variation can be made simple while an enabled one stays.
expect( varconv_take_out_validate( array( 12 ), $children, array( 11 ), array() ), array(), 'disabled one' );

echo "Make simple tests passed.\n";
