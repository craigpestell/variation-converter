/*
 * The Combine tab: create, undo and skip groups without a reload, the filter and SKU shading inside a group, row
 * order, the price preview, remembered unchecked products, the taken-SKU warning and "Create selected groups" in
 * batches. Every form also posts normally without this script. Undo asks first everywhere.
 */
( function () {
	var settings = window.varconv || null;
	var strings = settings ? settings.strings : {};

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return text.replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ position - 1 ] : args[ next++ ];
			return String( value );
		} );
	}

	function price( amount ) {
		var p = settings.price;
		var fixed = Number( amount ).toFixed( p.decimals ).split( '.' );
		var whole = fixed[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, p.thousand );
		var number = whole + ( fixed[ 1 ] ? p.decimal + fixed[ 1 ] : '' );
		return format( p.format, p.symbol, number );
	}

	function post( data ) {
		return window.fetch( settings.ajax, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: { message: strings.failed } };
			} );
		} );
	}

	function notice( kind, html ) {
		var div = document.createElement( 'div' );
		div.className = 'notice notice-' + kind + ' inline';
		div.innerHTML = html;
		return div;
	}

	function text( value ) {
		var p = document.createElement( 'p' );
		p.textContent = value;
		return p.outerHTML;
	}

	function rows( card ) {
		return Array.prototype.slice.call( card.querySelectorAll( '.varconv-members tbody tr' ) );
	}

	function used( row ) {
		return row.querySelector( '.varconv-use' ).checked;
	}

	/* The count and price range in the group's row and under its table, and the check-all box. */
	function refresh( card ) {
		var all = rows( card );
		var picked = all.filter( used );
		var prices = picked.map( function ( row ) {
			return parseFloat( row.dataset.price );
		} ).filter( function ( n ) {
			return n > 0;
		} );
		var range = '';
		if ( prices.length ) {
			var low = Math.min.apply( null, prices );
			var high = Math.max.apply( null, prices );
			range = low === high ? price( low ) : price( low ) + ' – ' + price( high );
		}
		var group = card.closest( '.varconv-group' );
		group.querySelector( '.varconv-count' ).textContent = format( strings.count, picked.length, all.length );
		group.querySelector( '.varconv-range' ).textContent = range;
		card.querySelector( '.varconv-preview-range' ).textContent = range;
		var checkAll = card.querySelector( '.varconv-check-all' );
		checkAll.checked = all.length > 0 && picked.length === all.length;
		checkAll.indeterminate = picked.length > 0 && picked.length < all.length;
	}

	/* Remembers which products are unchecked, so the card and bulk runs leave them out next time. */
	function remember( card, changed ) {
		var on = changed.filter( function ( row ) {
			return ! used( row );
		} );
		var off = changed.filter( used );
		var group = card.closest( '.varconv-group' );
		// Products picked by hand are not a scan group, so there is nothing to remember them by.
		if ( group.dataset.manual ) {
			return;
		}
		var key = group.dataset.key;
		[ [ on, 1 ], [ off, 0 ] ].forEach( function ( pair ) {
			if ( ! pair[ 0 ].length ) {
				return;
			}
			var data = new window.FormData();
			data.append( 'action', 'varconv_exclude' );
			data.append( '_wpnonce', settings.nonce );
			data.append( 'key', key );
			data.append( 'on', pair[ 1 ] );
			pair[ 0 ].forEach( function ( row ) {
				data.append( 'ids[]', row.dataset.id );
			} );
			post( data );
		} );
	}

	function setUsed( card, list, value ) {
		var changed = list.filter( function ( row ) {
			return used( row ) !== value;
		} );
		changed.forEach( function ( row ) {
			row.querySelector( '.varconv-use' ).checked = value;
		} );
		remember( card, changed );
		refresh( card );
	}

	function shown( row ) {
		return ! row.hidden;
	}

	/*
	 * Shades rows by their SKU's first N characters: rows that match up to there share a shade, and the shade
	 * alternates from one run to the next. The first row of each run can check just that run.
	 */
	function bands( card ) {
		var input = card.querySelector( '.varconv-sku-chars' );
		var n = input ? Math.max( 0, parseInt( input.value, 10 ) || 0 ) : 0;
		var last = null;
		var odd = false;
		rows( card ).forEach( function ( row ) {
			var link = row.querySelector( '.varconv-member-sku' );
			var sku = link.dataset.sku;
			var old = row.querySelector( '.varconv-only-band' );
			if ( old ) {
				old.remove();
			}
			row.classList.remove( 'varconv-band-a', 'varconv-band-b' );
			link.textContent = sku;
			if ( ! n || ! shown( row ) ) {
				return;
			}
			var prefix = sku.slice( 0, n ).toUpperCase();
			var start = prefix !== last;
			if ( start ) {
				odd = ! odd;
				last = prefix;
			}
			row.classList.add( odd ? 'varconv-band-a' : 'varconv-band-b' );
			row.dataset.band = prefix;
			var mark = document.createElement( 'mark' );
			mark.textContent = sku.slice( 0, n );
			link.textContent = sku.slice( n );
			link.insertBefore( mark, link.firstChild );
			if ( start ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button-link varconv-only-band';
				button.textContent = strings.onlyBand;
				button.setAttribute( 'aria-label', format( strings.onlyLabel, sku.slice( 0, n ) ) );
				link.parentNode.appendChild( button );
			}
		} );
	}

	/* After a create: drop the products it used; two or more left stay a group to create from next. */
	function created( card, data ) {
		var group = card.closest( '.varconv-group' );
		data.members.forEach( function ( id ) {
			var row = card.querySelector( 'tr[data-id="' + id + '"]' );
			if ( row ) {
				row.remove();
			}
		} );
		var left = rows( card );
		if ( left.length < 2 ) {
			var pick = group.querySelector( '.varconv-pick' );
			if ( pick ) {
				pick.remove();
			}
			group.classList.add( 'is-done' );
			group.querySelector( 'details' ).replaceWith( notice( 'success', data.html ) );
			updateBulk();
			return;
		}
		var message = card.querySelector( '.varconv-card-message' );
		message.innerHTML = '';
		message.appendChild( notice( 'success', data.html + text( format( strings.remaining, left.length ) ) ) );
		left.forEach( function ( row ) {
			row.querySelector( '.varconv-use' ).checked = true;
		} );
		remember( card, left );
		// The parent SKU just went to the new product.
		var sku = card.querySelector( '.varconv-parent-sku' );
		sku.value = '';
		card.querySelector( '.varconv-sku-note' ).textContent = '';
		var filter = card.querySelector( '.varconv-row-filter' );
		if ( filter ) {
			filter.value = '';
			filterRows( card );
		}
		bands( card );
		refresh( card );
	}

	function filterRows( card ) {
		var filter = card.querySelector( '.varconv-row-filter' );
		var needle = filter ? filter.value.trim().toLowerCase() : '';
		rows( card ).forEach( function ( row ) {
			row.hidden = '' !== needle && -1 === row.dataset.search.indexOf( needle );
		} );
		bands( card );
	}

	function submitCard( card, dismiss ) {
		var message = card.querySelector( '.varconv-card-message' );
		var buttons = card.querySelectorAll( '.varconv-actions button' );
		var data = new window.FormData( card );
		if ( dismiss ) {
			data.append( 'dismiss', '1' );
		}
		buttons.forEach( function ( b ) {
			b.disabled = true;
		} );
		message.innerHTML = '';
		message.appendChild( notice( 'info', text( strings.working ) ) );
		post( data ).then( function ( response ) {
			buttons.forEach( function ( b ) {
				b.disabled = false;
			} );
			message.innerHTML = '';
			if ( ! response.success ) {
				message.appendChild( notice( 'error', text( ( response.data && response.data.message ) || strings.failed ) ) );
				message.scrollIntoView( { block: 'nearest' } );
				return;
			}
			if ( response.data.dismissed ) {
				var group = card.closest( '.varconv-group' );
				var pick = group.querySelector( '.varconv-pick' );
				if ( pick ) {
					pick.remove();
				}
				group.classList.add( 'is-done' );
				group.querySelector( 'details' ).replaceWith( notice( 'info', text( strings.skipped ) ) );
				updateBulk();
				return;
			}
			created( card, response.data );
		}, function () {
			buttons.forEach( function ( b ) {
				b.disabled = false;
			} );
			message.innerHTML = '';
			message.appendChild( notice( 'error', text( strings.failed ) ) );
		} );
	}

	function submitUndo( form ) {
		var data = new window.FormData( form );
		var button = form.querySelector( 'button' );
		button.disabled = true;
		post( data ).then( function ( response ) {
			var box = form.closest( '.notice' ) || form;
			if ( ! response.success ) {
				button.disabled = false;
				form.after( notice( 'error', text( ( response.data && response.data.message ) || strings.failed ) ) );
				return;
			}
			box.replaceWith( notice( 'success', text( strings.undone ) ) );
		} );
	}

	/* "Create selected groups": the checked groups, or all that match, a batch at a time. */
	var bulk = document.getElementById( 'varconv-bulk' );

	function picks() {
		return Array.prototype.slice.call( document.querySelectorAll( '.varconv-pick' ) );
	}

	function updateBulk() {
		if ( ! bulk ) {
			return;
		}
		var all = bulk.querySelector( '.varconv-pick-all' );
		var any = picks().some( function ( p ) {
			return p.checked;
		} ) || ( all && all.checked );
		bulk.querySelector( '.varconv-bulk-create' ).disabled = ! any;
	}

	function runBulk() {
		var all = bulk.querySelector( '.varconv-pick-all' );
		var keys = all && all.checked ? JSON.parse( bulk.dataset.keys ) : picks().filter( function ( p ) {
			return p.checked;
		} ).map( function ( p ) {
			return p.value;
		} );
		var progress = bulk.querySelector( '.varconv-progress' );
		var button = bulk.querySelector( '.varconv-bulk-create' );
		var totals = { made: 0, skip: 0, failed: 0 };
		var done = 0;
		var size = 10;
		button.disabled = true;
		progress.hidden = false;
		progress.textContent = format( strings.progress, 0, keys.length );
		function next() {
			if ( done >= keys.length ) {
				progress.textContent = format( strings.bulkDone, totals.made, totals.skip, totals.failed );
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
				return;
			}
			var data = new window.FormData();
			data.append( 'action', 'varconv_bulk' );
			data.append( '_wpnonce', bulk.querySelector( '[name="_wpnonce"]' ).value );
			keys.slice( done, done + size ).forEach( function ( key ) {
				data.append( 'keys[]', key );
			} );
			post( data ).then( function ( response ) {
				if ( ! response.success ) {
					progress.textContent = ( response.data && response.data.message ) || strings.failed;
					button.disabled = false;
					return;
				}
				totals.made += response.data.made;
				totals.skip += response.data.skip;
				totals.failed += response.data.failed;
				done += size;
				progress.textContent = format( strings.progress, Math.min( done, keys.length ), keys.length );
				next();
			}, function () {
				progress.textContent = strings.failed;
				button.disabled = false;
			} );
		}
		next();
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form.matches( 'form.varconv-undo' ) ) {
			if ( ! window.confirm( form.dataset.confirm ) ) {
				event.preventDefault();
				return;
			}
			// On the Combine tab, undo in place; elsewhere the page reloads into History.
			if ( settings && form.closest( '.varconv-group, .varconv-created' ) ) {
				event.preventDefault();
				submitUndo( form );
			}
			return;
		}
		if ( ! settings ) {
			return;
		}
		if ( form.matches( 'form.varconv-card' ) ) {
			event.preventDefault();
			submitCard( form, !! ( event.submitter && event.submitter.name === 'dismiss' ) );
		} else if ( form === bulk ) {
			event.preventDefault();
			runBulk();
		}
	} );

	if ( ! settings ) {
		return;
	}
	updateBulk();

	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		var card = input.closest( 'form.varconv-card' );
		if ( input.matches( '.varconv-use' ) ) {
			remember( card, [ input.closest( 'tr' ) ] );
			refresh( card );
		} else if ( input.matches( '.varconv-check-all' ) ) {
			setUsed( card, rows( card ), input.checked );
		} else if ( input.matches( '.varconv-pick-page' ) ) {
			picks().forEach( function ( p ) {
				p.checked = input.checked;
			} );
			updateBulk();
		} else if ( input.matches( '.varconv-pick-all' ) ) {
			if ( input.checked ) {
				picks().forEach( function ( p ) {
					p.checked = true;
				} );
				bulk.querySelector( '.varconv-pick-page' ).checked = true;
			}
			updateBulk();
		} else if ( input.matches( '.varconv-pick' ) ) {
			var all = bulk && bulk.querySelector( '.varconv-pick-all' );
			if ( all && ! input.checked ) {
				all.checked = false;
			}
			updateBulk();
		}
	} );

	var skuTimer = null;
	document.addEventListener( 'input', function ( event ) {
		var input = event.target;
		var card = input.closest( 'form.varconv-card' );
		if ( ! card ) {
			return;
		}
		if ( input.matches( '.varconv-row-filter' ) ) {
			filterRows( card );
		} else if ( input.matches( '.varconv-sku-chars' ) ) {
			bands( card );
		} else if ( input.matches( '.varconv-attribute' ) ) {
			card.querySelector( '.varconv-attribute-name' ).textContent = input.value;
		} else if ( input.matches( '.varconv-parent-sku' ) ) {
			window.clearTimeout( skuTimer );
			skuTimer = window.setTimeout( function () {
				var note = card.querySelector( '.varconv-sku-note' );
				var data = new window.FormData();
				data.append( 'action', 'varconv_sku' );
				data.append( '_wpnonce', settings.nonce );
				data.append( 'sku', input.value.trim() );
				post( data ).then( function ( response ) {
					note.textContent = response.success ? response.data.message : '';
				} );
			}, 400 );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( 'button' );
		var card = button && button.closest( 'form.varconv-card' );
		if ( ! card ) {
			return;
		}
		var row = button.closest( 'tr' );
		if ( button.matches( '.varconv-up' ) && row.previousElementSibling ) {
			row.parentNode.insertBefore( row, row.previousElementSibling );
			button.focus();
			bands( card );
		} else if ( button.matches( '.varconv-down' ) && row.nextElementSibling ) {
			row.parentNode.insertBefore( row.nextElementSibling, row );
			button.focus();
			bands( card );
		} else if ( button.matches( '.varconv-check-shown' ) ) {
			var list = rows( card );
			setUsed( card, list.filter( shown ), true );
			setUsed( card, list.filter( function ( r ) {
				return ! shown( r );
			} ), false );
		} else if ( button.matches( '.varconv-uncheck-shown' ) ) {
			setUsed( card, rows( card ).filter( shown ), false );
		} else if ( button.matches( '.varconv-only-band' ) ) {
			var band = row.dataset.band;
			var all = rows( card );
			setUsed( card, all.filter( function ( r ) {
				return shown( r ) && r.dataset.band === band;
			} ), true );
			setUsed( card, all.filter( function ( r ) {
				return ! shown( r ) || r.dataset.band !== band;
			} ), false );
		}
	} );
}() );
