<?php
/** Offline checks for attribute spelling (includes/attributes.php): php tests/test-attributes.php */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/attributes.php';

function expect( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . json_encode( array( 'expected' => $expected, 'actual' => $actual ), JSON_UNESCAPED_SLASHES ) );
	}
}

$pairs = varconv_spelling_pairs();
expect( varconv_spell( 'Color', 'british', $pairs ), 'Colour', 'Color to British' );
expect( varconv_spell( 'colour', 'british', $pairs ), 'Colour', 'case fixed' );
expect( varconv_spell( ' FLAVOR ', 'british', $pairs ), 'Flavour', 'Flavor to British, trimmed' );
expect( varconv_spell( 'Colour', 'american', $pairs ), 'Color', 'Colour to American' );
expect( varconv_spell( 'Flavour', 'american', $pairs ), 'Flavor', 'Flavour to American' );
expect( varconv_spell( 'Size', 'british', $pairs ), 'Size', 'other names kept' );
expect( varconv_spell( 'Colorway', 'british', $pairs ), 'Colorway', 'whole names only' );
expect( varconv_spell( 'Grey', 'american', array( 'Gray' => 'Grey' ) ), 'Gray', 'pairs given' );

echo "Attribute spelling tests passed.\n";
