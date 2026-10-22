<?php
/** Offline checks for the pure split helpers (includes/split.php). */
require_once __DIR__ . '/../includes/split.php';

function expect( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . json_encode( array( 'expected' => $expected, 'actual' => $actual ), JSON_UNESCAPED_SLASHES ) );
	}
}

// Names: the variable product's name and the variation's values.
expect( varconv_split_name( 'Tri-Rings', array( 'Black' ) ), 'Tri-Rings Black', 'one value' );
expect( varconv_split_name( 'T-Shirt ', array( 'Blue', ' Large ' ) ), 'T-Shirt Blue Large', 'two values' );
expect( varconv_split_name( 'Mug', array( '', 'Red' ) ), 'Mug Red', 'empty value skipped' );
expect( varconv_split_name( 'Mug', array() ), 'Mug', 'no values' );

// Validation of what the admin submits.
expect( varconv_split_validate( array( 11 => 'Tri-Rings Black', 12 => 'Tri-Rings Blue' ) ), array(), 'valid' );
expect( varconv_split_validate( array( 11 => 'Tri-Rings Black' ) ), array(), 'one variation is enough' );
expect( varconv_split_validate( array() ), array( 'Choose at least 1 variation.' ), 'none chosen' );
expect( varconv_split_validate( array( 11 => ' ', 12 => 'X' ) ), array( 'Variation 11 needs a product name.' ), 'empty name' );

echo "Split tests passed.\n";
