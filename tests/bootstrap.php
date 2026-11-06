<?php
/** Lets the pure includes load without WordPress: the ABSPATH guard, and translation stubs that return the text. */
define( 'ABSPATH', __DIR__ . '/' );

function __( $text ) {
	return $text;
}

function _n( $single, $plural, $number ) {
	return 1 === $number ? $single : $plural;
}

// Hook registrations in the includes do nothing offline.
function add_action( ...$args ) {
	return true;
}

function add_filter( ...$args ) {
	return true;
}
