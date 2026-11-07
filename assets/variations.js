/* Product edit screen > Variations: keep a click on "Make simple product" (includes/admin-variations.php) from opening or closing the variation. */
/* global jQuery */
jQuery( function ( $ ) {
	$( document ).on( 'click', '.woocommerce_variation h3 .varconv-make-simple, .woocommerce_variation h3 .varconv-made-simple a', function ( event ) {
		event.stopPropagation();
	} );
} );
