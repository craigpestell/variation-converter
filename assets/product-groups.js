/* Products > All Products: a combined product's variants as rows beneath it (includes/admin-product-groups.php). */
/* global jQuery, wp, varconvGroups */
jQuery( function ( $ ) {
	var $list = $( '#the-list' );
	var $controls = $( '.varconv-group-controls' );
	var cache = {};

	// The list's columns, so each variant row has a cell under each column, hidden where the column is.
	function columns() {
		return $list.closest( 'table' ).find( 'thead tr' ).first().children().map( function () {
			var $c = $( this );
			return {
				key: 'cb' === this.id ? 'cb' : String( this.id || '' ),
				primary: $c.hasClass( 'column-primary' ),
				hidden: $c.hasClass( 'hidden' ),
				name: $c.text().trim(),
			};
		} ).get();
	}

	function link( item ) {
		var $p = $( '<span class="varconv-child-link"></span>' ).text( varconvGroups.kinds[ item.kind ] + ' ' );
		$( '<a></a>' ).attr( 'href', item.editUrl ).text( item.name ).appendTo( $p );
		return $p;
	}

	function nameCell( v ) {
		var $cell = $( '<div class="varconv-child-name"></div>' );
		var $title = $( '<strong></strong>' ).text( v.label ).appendTo( $cell );
		if ( ! v.enabled ) {
			$title.append( ' ', $( '<span class="post-state"></span>' ).text( '— ' + varconvGroups.disabled ) );
		}
		// On a phone only the name column shows, so SKU, price and stock go under the name there.
		var $meta = $( '<div class="varconv-child-meta"></div>' ).appendTo( $cell );
		if ( v.sku ) {
			$( '<span></span>' ).text( varconvGroups.sku.replace( '%s', v.sku ) ).appendTo( $meta );
		}
		$( '<span></span>' ).html( v.price ).appendTo( $meta );
		$( '<span></span>' ).html( v.stock ).appendTo( $meta );
		if ( v.original ) {
			$cell.append( link( v.original ) );
		}
		if ( v.taken ) {
			$cell.append( link( v.taken ) );
		}
		if ( v.viewUrl ) {
			$( '<div class="row-actions"></div>' ).append( $( '<a></a>' ).attr( 'href', v.viewUrl ).text( varconvGroups.view ) ).appendTo( $cell );
		}
		return $cell;
	}

	function row( id, v, cols ) {
		var $tr = $( '<tr class="varconv-child"></tr>' ).attr( 'data-varconv-parent', id );
		$.each( cols, function ( i, c ) {
			var $td = 'cb' === c.key ? $( '<th scope="row" class="check-column"></th>' ) : $( '<td></td>' ).addClass( c.key + ' column-' + c.key );
			if ( c.hidden ) {
				$td.addClass( 'hidden' );
			}
			if ( c.primary ) {
				$td.addClass( 'column-primary' );
			}
			$td.attr( 'data-colname', c.name );
			if ( 'thumb' === c.key ) {
				$td.html( v.image );
			} else if ( 'name' === c.key ) {
				$td.append( nameCell( v ) );
			} else if ( 'sku' === c.key ) {
				$td.text( v.sku || '–' );
			} else if ( 'price' === c.key ) {
				$td.html( v.price );
			} else if ( 'is_in_stock' === c.key ) {
				$td.html( v.stock );
			}
			$tr.append( $td );
		} );
		return $tr;
	}

	function message( id, text, cols ) {
		var visible = $.grep( cols, function ( c ) {
			return ! c.hidden;
		} ).length;
		return $( '<tr class="varconv-child varconv-child-message"></tr>' ).attr( 'data-varconv-parent', id )
			.append( $( '<td></td>' ).attr( 'colspan', visible ).text( text ) );
	}

	function toggles() {
		return $list.find( '.varconv-group-toggle' );
	}

	function setOpen( $button, open ) {
		$button.attr( 'aria-expanded', open ? 'true' : 'false' );
		$button.find( '.dashicons' ).toggleClass( 'dashicons-arrow-right-alt2', ! open ).toggleClass( 'dashicons-arrow-down-alt2', open );
		$button.closest( 'tr' ).toggleClass( 'varconv-open', open );
	}

	function collapse( id ) {
		$list.find( 'tr.varconv-child[data-varconv-parent="' + id + '"]' ).remove();
		setOpen( toggles().filter( '[data-id="' + id + '"]' ), false );
	}

	function draw( id, rows ) {
		var cols = columns();
		var $after = $( '#post-' + id );
		$list.find( 'tr.varconv-child[data-varconv-parent="' + id + '"]' ).remove();
		if ( ! rows || ! rows.length ) {
			$after.after( message( id, varconvGroups.none, cols ) );
			return;
		}
		// Every other variant shaded, counted from the group's first row.
		$after.after( $.map( rows, function ( v, i ) {
			return row( id, v, cols ).toggleClass( 'varconv-alt', 1 === i % 2 );
		} ) );
	}

	// Expands the rows of these product IDs, asking the server once for those not loaded yet.
	function expand( ids ) {
		var missing = $.grep( ids, function ( id ) {
			return ! cache[ id ];
		} );
		$.each( ids, function ( i, id ) {
			setOpen( toggles().filter( '[data-id="' + id + '"]' ), true );
			if ( cache[ id ] ) {
				draw( id, cache[ id ] );
			} else {
				$( '#post-' + id ).after( message( id, varconvGroups.loading, columns() ).addClass( 'varconv-loading' ) );
			}
		} );
		if ( ! missing.length ) {
			return;
		}
		wp.apiFetch( { path: varconvGroups.path + '?ids=' + missing.join( ',' ) } ).then( function ( data ) {
			$.each( missing, function ( i, id ) {
				cache[ id ] = ( data && data[ id ] ) || [];
				if ( 'true' === toggles().filter( '[data-id="' + id + '"]' ).attr( 'aria-expanded' ) ) {
					draw( id, cache[ id ] );
				}
			} );
		}, function () {
			$.each( missing, function ( i, id ) {
				$list.find( 'tr.varconv-child[data-varconv-parent="' + id + '"]' ).remove();
				$( '#post-' + id ).after( message( id, varconvGroups.failed, columns() ) );
			} );
		} );
	}

	$list.on( 'click', '.varconv-group-toggle', function ( e ) {
		e.preventDefault();
		var id = String( $( this ).data( 'id' ) );
		if ( 'true' === $( this ).attr( 'aria-expanded' ) ) {
			collapse( id );
		} else {
			expand( [ id ] );
		}
	} );

	// Quick Edit replaces the product's row, so close its group first.
	$list.on( 'click', '.editinline', function () {
		collapse( String( $( this ).closest( 'tr' ).attr( 'id' ) || '' ).replace( 'post-', '' ) );
	} );

	$controls.find( '.varconv-expand-all' ).on( 'click', function () {
		expand( toggles().map( function () {
			return String( $( this ).data( 'id' ) );
		} ).get() );
	} );

	$controls.find( '.varconv-collapse-all' ).on( 'click', function () {
		toggles().each( function () {
			collapse( String( $( this ).data( 'id' ) ) );
		} );
	} );

	$controls.find( '.varconv-group-switch' ).on( 'change', function () {
		window.location.href = $( this ).data( 'url' );
	} );

	if ( toggles().length ) {
		$controls.find( '.varconv-expand-all, .varconv-collapse-all' ).removeClass( 'hidden' );
	}
} );
