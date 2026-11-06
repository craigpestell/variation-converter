/* Products > All Products > Quick Edit: one stock box per variant on the variable products the plugin made (includes/admin-quick-edit.php). */
/* global jQuery, varconvQuickEdit */
jQuery( function ( $ ) {
	var parentFields = '.manage_stock_field, .stock_status_field, .stock_fields, .backorder_field';

	function fill( id ) {
		var $row = $( '#edit-' + id );
		var $box = $row.find( '.varconv-variant-stock' );
		var $data = $( '#varconv_stock_' + id );
		var $list = $box.find( '.varconv-variant-stock-list' ).empty();
		if ( ! $row.length || ! $data.length ) {
			$box.addClass( 'hidden' );
			return;
		}
		var variants = $data.data( 'variants' ) || [];
		$.each( variants, function ( i, v ) {
			var qty = null === v.qty ? '' : String( v.qty );
			var $label = $( '<label class="varconv-variant-stock-item"></label>' );
			$( '<span class="varconv-variant-stock-name"></span>' ).text( v.label ).appendTo( $label );
			if ( v.sku && v.sku !== v.label ) {
				$( '<span class="varconv-variant-stock-sku"></span>' ).text( varconvQuickEdit.sku.replace( '%s', v.sku ) ).appendTo( $label );
			}
			$( '<input type="number" step="any" class="small-text">' ).attr( 'name', 'varconv_stock[' + v.id + ']' ).val( qty ).appendTo( $label );
			$( '<input type="hidden">' ).attr( 'name', 'varconv_stock_was[' + v.id + ']' ).val( qty ).appendTo( $label );
			$list.append( $label );
		} );
		$box.removeClass( 'hidden' );
		// When every variant keeps its own stock, the variable product's stock fields set a count nothing sells from.
		// Hide them, and clear any such count left by an earlier Quick Edit.
		if ( '1' === String( $data.data( 'all-own' ) ) ) {
			$row.find( parentFields ).hide();
			$row.find( 'input[name="_manage_stock"]' ).prop( 'checked', false );
			$row.find( 'input[name="_stock"]' ).val( '' );
			$row.find( 'select[name="_stock_status"]' ).val( '' );
		}
	}

	// After WordPress builds the edit row and WooCommerce fills its fields.
	$( '#the-list' ).on( 'click', '.editinline', function () {
		var id = String( $( this ).closest( 'tr' ).attr( 'id' ) || '' ).replace( 'post-', '' );
		window.setTimeout( function () {
			fill( id );
		}, 0 );
	} );
} );
