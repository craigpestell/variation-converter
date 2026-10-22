/* Ask before an Undo deletes products. */
document.addEventListener( 'submit', function ( event ) {
	var form = event.target;
	if ( form.matches( 'form.varconv-undo' ) && ! window.confirm( form.dataset.confirm ) ) {
		event.preventDefault();
	}
} );
