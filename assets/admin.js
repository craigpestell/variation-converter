/*
 * The Combine tab: open a group's review card under its row, create, undo and skip without a reload, Select by
 * SKU, drag to reorder, the live count and price, remembered unchecked products, the taken-SKU warning, starting the
 * Create bulk action, and following its progress on every tab. Every link and form also works without this script.
 * Undo asks first everywhere.
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

	function shown( row ) {
		return ! row.hidden;
	}

	/* The group's row in the list, above its card. */
	function groupRow( card ) {
		return document.getElementById( 'varconv-row-' + card.dataset.key );
	}

	function reviewRow( card ) {
		return card.closest( '.varconv-review' );
	}

	/* The count and price range in the group's row, row shading, and the check-all box. */
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
		var row = groupRow( card );
		if ( row ) {
			row.querySelector( '.column-products' ).textContent = picked.length === all.length ? String( all.length ) : format( strings.count, picked.length, all.length );
			row.querySelector( '.column-price' ).textContent = range;
		}
		all.forEach( function ( r ) {
			r.classList.toggle( 'is-off', ! used( r ) );
		} );
		var checkAll = card.querySelector( '.varconv-check-all' );
		checkAll.checked = all.length > 0 && picked.length === all.length;
		checkAll.indeterminate = picked.length > 0 && picked.length < all.length;
	}

	/* Remembers which products are unchecked, so the card and bulk runs leave them out next time. */
	function remember( card, changed ) {
		// Products picked by hand are not a scan group, so there is nothing to remember them by.
		if ( card.dataset.manual ) {
			return;
		}
		var on = changed.filter( function ( row ) {
			return ! used( row );
		} );
		var off = changed.filter( used );
		[ [ on, 1 ], [ off, 0 ] ].forEach( function ( pair ) {
			if ( ! pair[ 0 ].length ) {
				return;
			}
			var data = new window.FormData();
			data.append( 'action', 'varconv_exclude' );
			data.append( '_wpnonce', settings.nonce );
			data.append( 'key', card.dataset.key );
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

	/* Opens or closes a group's card under its row. */
	function toggle( key, open ) {
		var row = document.getElementById( 'varconv-row-' + key );
		var review = document.getElementById( 'varconv-' + key );
		if ( ! row || ! review ) {
			return;
		}
		open = undefined === open ? review.hidden : open;
		review.hidden = ! open;
		row.classList.toggle( 'varconv-open', open );
		row.querySelectorAll( '.row-title' ).forEach( function ( link ) {
			link.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			link.querySelector( '.varconv-toggle' ).innerHTML = open ? '&#9662;' : '&#9656;';
		} );
		if ( open ) {
			sortable( review.querySelector( '.varconv-members tbody' ) );
			var first = review.querySelector( 'input[name="name"]' );
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		}
	}

	/* Drag to reorder, with the handle; the up and down buttons do the same from the keyboard. */
	function sortable( body ) {
		var $ = window.jQuery;
		if ( ! body || ! $ || ! $.fn.sortable || body.dataset.sortable ) {
			return;
		}
		body.dataset.sortable = '1';
		$( body ).sortable( {
			handle: '.varconv-handle',
			axis: 'y',
			items: '> tr',
			helper: function ( event, tr ) {
				// Keep the cells' widths while dragging.
				var cells = tr.children();
				var helper = tr.clone();
				helper.children().each( function ( i ) {
					$( this ).width( cells.eq( i ).width() );
				} );
				return helper;
			},
		} );
	}

	/* Marks a group's row done (created or skipped) and takes it out of the bulk selection. */
	function finish( card, badge, kind ) {
		var row = groupRow( card );
		if ( ! row ) {
			return;
		}
		row.classList.add( 'is-done' );
		var pick = row.querySelector( '.varconv-pick' );
		if ( pick ) {
			pick.checked = false;
			pick.disabled = true;
		}
		var actions = row.querySelector( '.row-actions' );
		if ( actions ) {
			actions.remove();
		}
		row.querySelector( '.column-status' ).innerHTML = '<span class="varconv-badge varconv-badge-' + kind + '"></span>';
		row.querySelector( '.column-status .varconv-badge' ).textContent = badge;
		updateBulk();
	}

	/* The page again with a notice (and a group to open), so counts, prices and suggestions are fresh. */
	function reload( params, open ) {
		var url = new window.URL( window.location.href );
		[ 'open', 'varconv_done', 'varconv_product', 'varconv_made', 'varconv_skip', 'varconv_failed_count', 'varconv_count' ].forEach( function ( name ) {
			url.searchParams.delete( name );
		} );
		Object.keys( params ).forEach( function ( name ) {
			url.searchParams.set( name, params[ name ] );
		} );
		if ( open ) {
			url.searchParams.set( 'open', open );
		}
		url.hash = open ? 'varconv-' + open : '';
		window.location.assign( url.toString() );
	}

	/*
	 * After a create. Part of a group: reload, opening what is left of it, since its warnings, SKU buttons and
	 * suggested SKU have changed. The whole group: mark its row created in place.
	 */
	function created( card, data ) {
		if ( data.members.length < rows( card ).length ) {
			reload( { varconv_done: 'created', varconv_product: data.id }, data.next );
			return;
		}
		var message = card.querySelector( '.varconv-card-message' );
		message.innerHTML = '';
		finish( card, strings.created, 'ready' );
		message.appendChild( notice( 'success', data.html ) );
		Array.prototype.slice.call( card.children ).forEach( function ( child ) {
			if ( ! child.classList.contains( 'varconv-card-message' ) ) {
				child.hidden = true;
			}
		} );
	}

	function filterRows( card ) {
		var filter = card.querySelector( '.varconv-row-filter' );
		var needle = filter ? filter.value.trim().toLowerCase() : '';
		rows( card ).forEach( function ( row ) {
			row.hidden = '' !== needle && -1 === row.dataset.search.indexOf( needle );
		} );
	}

	function submitCard( card, dismiss ) {
		var message = card.querySelector( '.varconv-card-message' );
		var buttons = card.querySelectorAll( '.varconv-review-foot button' );
		var data = new window.FormData( card );
		if ( dismiss ) {
			data.append( 'dismiss', '1' );
		}
		buttons.forEach( function ( b ) {
			b.disabled = true;
		} );
		message.innerHTML = '';
		message.appendChild( notice( 'info', text( strings.working ) ) );
		function done() {
			buttons.forEach( function ( b ) {
				b.disabled = false;
			} );
			message.innerHTML = '';
		}
		post( data ).then( function ( response ) {
			done();
			if ( ! response.success ) {
				message.appendChild( notice( 'error', text( ( response.data && response.data.message ) || strings.failed ) ) );
				message.scrollIntoView( { block: 'nearest' } );
				return;
			}
			if ( response.data.dismissed ) {
				finish( card, strings.skippedBadge, 'skipped' );
				toggle( card.dataset.key, false );
				return;
			}
			created( card, response.data );
		}, function () {
			done();
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

	/* Bulk actions: Create runs a batch at a time with progress; Skip and Restore post the form. */
	var bulk = document.getElementById( 'varconv-bulk' );

	function picks() {
		return Array.prototype.slice.call( document.querySelectorAll( '.varconv-pick' ) );
	}

	function allPicked() {
		var all = document.querySelector( '.varconv-pick-all' );
		return !! ( all && all.checked );
	}

	function updateBulk() {
		var head = document.querySelectorAll( '.varconv-pick-page' );
		var boxes = picks().filter( function ( p ) {
			return ! p.disabled;
		} );
		var every = boxes.length > 0 && boxes.every( function ( p ) {
			return p.checked;
		} );
		head.forEach( function ( h ) {
			h.checked = every;
		} );
	}

	function runBulk( action ) {
		var keys = allPicked() ? JSON.parse( bulk.dataset.keys ) : picks().filter( function ( p ) {
			return p.checked;
		} ).map( function ( p ) {
			return p.value;
		} );
		var progress = document.querySelector( '.varconv-progress' );
		if ( ! keys.length ) {
			progress.hidden = false;
			progress.innerHTML = text( strings.noGroups );
			return;
		}
		if ( 'create' !== action ) {
			bulk.submit();
			return;
		}
		var data = new window.FormData();
		data.append( 'action', 'varconv_bulk' );
		data.append( '_wpnonce', bulk.querySelector( '[name="_wpnonce"]' ).value );
		keys.forEach( function ( key ) {
			data.append( 'keys[]', key );
		} );
		bulkButtons( true );
		progress.hidden = true;
		post( data ).then( function ( response ) {
			if ( ! response.success ) {
				bulkButtons( false );
				progress.hidden = false;
				progress.innerHTML = text( ( response.data && response.data.message ) || strings.failed );
				return;
			}
			showJob( response.data );
		}, function () {
			bulkButtons( false );
			progress.hidden = false;
			progress.innerHTML = text( strings.failed );
		} );
	}

	function bulkButtons( disabled ) {
		document.querySelectorAll( '.varconv-bulk-apply' ).forEach( function ( b ) {
			b.disabled = disabled;
		} );
	}

	/*
	 * The bulk create job runs on the server (includes/job.php) and finishes without this page. While any tab of the
	 * plugin page is open, this asks for the next batch as soon as one is done, and shows the progress.
	 */
	function jobBox() {
		var box = document.querySelector( '.varconv-job' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.setAttribute( 'aria-live', 'polite' );
			document.querySelector( '.varconv .nav-tab-wrapper' ).after( box );
		}
		return box;
	}

	function showJob( job ) {
		if ( ! job.html ) {
			return;
		}
		var box = jobBox();
		box.className = job.class;
		box.innerHTML = job.html;
		if ( job.running ) {
			bulkButtons( true );
			window.setTimeout( followJob, job.worked ? 100 : 2000 );
			return;
		}
		bulkButtons( false );
		// The Combine list still shows the groups just created: load it again, with the summary.
		if ( bulk ) {
			reload( { varconv_done: 'bulk', varconv_made: job.made, varconv_skip: job.skip, varconv_failed_count: job.failed } );
		}
	}

	function jobRequest( action ) {
		var data = new window.FormData();
		data.append( 'action', action );
		data.append( '_wpnonce', settings.nonce );
		return post( data );
	}

	function followJob() {
		jobRequest( 'varconv_job' ).then( function ( response ) {
			if ( response.success ) {
				showJob( response.data );
			} else {
				window.setTimeout( followJob, 5000 );
			}
		}, function () {
			window.setTimeout( followJob, 5000 );
		} );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form.matches( 'form.varconv-undo' ) ) {
			if ( ! window.confirm( form.dataset.confirm ) ) {
				event.preventDefault();
				return;
			}
			// On the Combine tab, undo in place; elsewhere the page reloads into History.
			if ( settings && form.closest( '.varconv-card, .varconv-created' ) ) {
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
			submitCard( form, !! ( event.submitter && 'dismiss' === event.submitter.name ) );
		} else if ( form === bulk ) {
			event.preventDefault();
			var which = event.submitter && 'bottom' === event.submitter.value ? 'bulk_action2' : 'bulk_action';
			var select = document.querySelector( '[name="' + which + '"]' );
			var action = select ? select.value : '';
			if ( ! action ) {
				var progress = document.querySelector( '.varconv-progress' );
				progress.hidden = false;
				progress.innerHTML = text( strings.noAction );
				return;
			}
			// Bulk Apply submits the form with the menu that was used; keep both menus in step for a plain submit.
			document.querySelectorAll( '[name="bulk_action"], [name="bulk_action2"]' ).forEach( function ( s ) {
				s.value = action;
			} );
			runBulk( action );
		}
	} );

	if ( ! settings ) {
		return;
	}

	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		var card = input.closest( 'form.varconv-card' );
		if ( input.matches( '.varconv-use' ) ) {
			remember( card, [ input.closest( 'tr' ) ] );
			refresh( card );
		} else if ( input.matches( '.varconv-check-all' ) ) {
			setUsed( card, rows( card ).filter( shown ), input.checked );
		} else if ( input.matches( '.varconv-pick-page' ) ) {
			picks().forEach( function ( p ) {
				if ( ! p.disabled ) {
					p.checked = input.checked;
				}
			} );
			updateBulk();
		} else if ( input.matches( '.varconv-pick-all' ) ) {
			if ( input.checked ) {
				picks().forEach( function ( p ) {
					p.checked = ! p.disabled;
				} );
			}
			updateBulk();
		} else if ( input.matches( '.varconv-pick' ) ) {
			var all = document.querySelector( '.varconv-pick-all' );
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
		var stop = event.target.closest( '.varconv-job-stop' );
		if ( stop ) {
			stop.disabled = true;
			jobRequest( 'varconv_job_stop' );
			return;
		}
		var link = event.target.closest( 'a.varconv-review-toggle, a.varconv-cancel' );
		if ( link ) {
			var tr = link.closest( 'tr' );
			var key = tr.dataset.key || ( link.closest( '.varconv-card' ) || {} ).dataset.key;
			if ( key && document.getElementById( 'varconv-' + key ) ) {
				event.preventDefault();
				toggle( key, link.classList.contains( 'varconv-cancel' ) ? false : undefined );
			}
			return;
		}
		var button = event.target.closest( 'button' );
		var card = button && button.closest( 'form.varconv-card' );
		if ( ! card ) {
			return;
		}
		var row = button.closest( 'tr' );
		if ( button.matches( '.varconv-up' ) && row.previousElementSibling ) {
			row.parentNode.insertBefore( row, row.previousElementSibling );
			button.focus();
		} else if ( button.matches( '.varconv-down' ) && row.nextElementSibling ) {
			row.parentNode.insertBefore( row.nextElementSibling, row );
			button.focus();
		} else if ( button.matches( '.varconv-check-shown' ) ) {
			var list = rows( card );
			setUsed( card, list.filter( shown ), true );
			setUsed( card, list.filter( function ( r ) {
				return ! shown( r );
			} ), false );
		} else if ( button.matches( '.varconv-band-pick' ) ) {
			var prefix = button.dataset.prefix;
			var all = rows( card );
			var match = function ( r ) {
				return '' === prefix || 0 === r.dataset.sku.indexOf( prefix );
			};
			setUsed( card, all.filter( match ), true );
			setUsed( card, all.filter( function ( r ) {
				return ! match( r );
			} ), false );
			card.querySelectorAll( '.varconv-band-pick' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', b === button ? 'true' : 'false' );
			} );
		}
	} );

	// Cards open on load (a failed create, a link to one group) can be dragged right away.
	document.querySelectorAll( '.varconv-review:not([hidden]) .varconv-members tbody' ).forEach( sortable );
	updateBulk();
	if ( document.querySelector( '.varconv-job.is-running' ) ) {
		bulkButtons( true );
		followJob();
	}
}() );
