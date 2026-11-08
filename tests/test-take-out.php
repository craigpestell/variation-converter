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
expect( varconv_take_out_validate( array( 11 ), $children, array() ), array(), 'one of three' );
expect( varconv_take_out_validate( array( 11, 12 ), $children, array() ), array(), 'two of three' );
expect( varconv_take_out_validate( $children, $children, array() ), array(), 'all of them' );
expect( varconv_take_out_validate( array( 13 ), $children, array( 11, 12 ) ), array(), 'the last one' );
expect( varconv_take_out_validate( array(), $children, array() ), array( 'Choose at least 1 variation.' ), 'none chosen' );
expect( varconv_take_out_validate( array( 99 ), $children, array() ), array( 'Product 99 is not a variation of this product.' ), 'not a child' );
expect( varconv_take_out_validate( array( 12 ), $children, array( 12 ) ), array( 'Variation 12 is already a simple product.' ), 'already made simple' );

// The variable product is removed only when no variation is left for sale.
expect( varconv_take_out_empties( array( 11 ), $children ), false, 'one of three for sale' );
expect( varconv_take_out_empties( $children, $children ), true, 'every one for sale' );
expect( varconv_take_out_empties( array( 13 ), array( 13 ) ), true, 'the last one for sale' );
expect( varconv_take_out_empties( array( 12 ), array( 11 ) ), false, 'a disabled one while one stays for sale' );
expect( varconv_take_out_empties( array( 12 ), array() ), true, 'none was for sale' );
expect( varconv_take_out_empties( array(), $children ), false, 'none chosen' );

echo "Make simple tests passed.\n";
