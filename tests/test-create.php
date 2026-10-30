<?php
/** Offline checks for the pure combine helpers (includes/create.php): php tests/test-create.php */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/create.php';

function expect( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . json_encode( array( 'expected' => $expected, 'actual' => $actual ), JSON_UNESCAPED_SLASHES ) );
	}
}

// Gallery: every source's featured and gallery images, in order, each once, without the parent's own image.
expect( varconv_gallery( array( array( 1, 2, 3 ), array( 4, 5 ), array( 6 ) ) ), array( 2, 3, 4, 5, 6 ), 'all galleries kept' );
expect( varconv_gallery( array( array( 1, 2 ), array( 3, 2 ), array( 3, 1 ) ) ), array( 2, 3 ), 'shared images once' );
expect( varconv_gallery( array( array( 0, 2 ), array( 0 ), array( '7', 8 ) ) ), array( 2, 7, 8 ), 'missing images skipped' );
expect( varconv_gallery( array( array( 1 ) ) ), array(), 'one image only' );
expect( varconv_gallery( array() ), array(), 'no sources' );

// Grid order: groups' attribute first, or swapped with each pair of labels turned to match.
$pairs = array( 5 => array( 'Strawberry', '4oz' ), 6 => array( 'Cherry', '8oz' ) );
expect( varconv_grid_order( 'Flavor', 'Size', $pairs, false ), array( 'Flavor', 'Size', $pairs ), 'groups first by default' );
expect( varconv_grid_order( 'Flavor', 'Size', $pairs, true ), array( 'Size', 'Flavor', array( 5 => array( '4oz', 'Strawberry' ), 6 => array( '8oz', 'Cherry' ) ) ), 'swapped' );

echo "Create tests passed.\n";
