<?php
/** Offline checks for Add to a combined product (includes/add-to.php): php tests/test-add-to.php */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/scan.php';
require_once __DIR__ . '/../includes/add-to.php';

function expect( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . json_encode( array( 'expected' => $expected, 'actual' => $actual ), JSON_UNESCAPED_SLASHES ) );
	}
}

// Guesses: an option the name holds, else what the name has beyond the variable product's name, else the SKU.
expect( varconv_add_guess( 'Classic Tee Green', 'Classic Tee', array( array( 'Black', 'Blue' ) ), 'X1' ), array( 'Green' ), 'the rest of the name' );
expect( varconv_add_guess( 'Classic Tee Navy Blue', 'Classic Tee', array( array( 'Black', 'Blue' ) ), 'X1' ), array( 'Navy Blue' ), 'the rest, not a shorter option inside it' );
expect( varconv_add_guess( 'Summer Promo Shirt', 'Classic Tee', array( array( 'Black' ) ), 'PROMO-7' ), array( 'PROMO-7' ), 'nothing shared: the SKU' );
expect( varconv_add_guess( 'Summer Promo Shirt', 'Classic Tee', array( array( 'Black' ) ), '' ), array( '' ), 'no SKU either' );
expect( varconv_add_guess( 'Classic Tee blue', 'Classic Tee', array( array( 'Black', 'Blue' ) ), 'X1' ), array( 'Blue' ), 'an option, in its own spelling' );
expect( varconv_add_guess( 'Syrup Vanilla 750ml', 'Syrup', array( array( 'Hazelnut', 'Vanilla' ), array( '250ml', '750ml' ) ), 'S1' ), array( 'Vanilla', '750ml' ), 'both attributes named' );
expect( varconv_add_guess( 'Syrup Caramel 750ml', 'Syrup', array( array( 'Hazelnut', 'Vanilla' ), array( '250ml', '750ml' ) ), 'S1' ), array( 'Caramel', '750ml' ), 'one named, the rest is the other' );
expect( varconv_add_guess( 'Syrup Caramel 1L', 'Syrup', array( array( 'Hazelnut' ), array( '250ml' ) ), 'S1' ), array( '', '' ), 'two unknown: left for the admin' );

// Validation.
$existing = array( array( 'Black' ), array( 'Blue' ) );
expect( varconv_add_validate( array( 5 => array( 'Green' ) ), 1, $existing ), array(), 'a new label' );
expect( varconv_add_validate( array(), 1, $existing ), array( 'Choose at least 1 product to add.' ), 'nothing' );
expect( varconv_add_validate( array( 5 => array( '' ) ), 1, $existing ), array( 'Product 5 needs a label.' ), 'empty label' );
expect( varconv_add_validate( array( 5 => array( 'Red' ) ), 2, array() ), array( 'Product 5 needs a label for each attribute.' ), 'one of two' );
expect( varconv_add_validate( array( 5 => array( 'blue' ) ), 1, $existing ), array( 'There is already a "blue" variation. Each one needs its own labels.' ), 'an existing variation' );
expect( varconv_add_validate( array( 5 => array( 'Red' ), 6 => array( 'red ' ) ), 1, $existing ), array( 'There is already a "red" variation. Each one needs its own labels.' ), 'twice among the new ones' );
expect( varconv_add_validate( array( 5 => array( 'A|B' ) ), 1, $existing ), array( 'Labels cannot contain "|" (product 5).' ), 'a pipe' );
expect( varconv_add_validate( array( 5 => array( 'Red', 'Large' ) ), 2, array( array( 'Red', 'Small' ) ) ), array(), 'two attributes, a new pair' );

echo "Add to a combined product tests passed.\n";
