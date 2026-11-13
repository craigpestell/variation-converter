/*
 * The page's state lives in its URL, as it always has: tab, view, search, filters, page number, the open card. Links
 * from elsewhere in WordPress (Products > All Products, the product edit screen) and bookmarks keep working, and the
 * browser's Back button steps through what the admin did. Changing the query does not load the page again.
 */
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

const listeners = new Set();
// WordPress's own query args for this page; the rest is the app's.
const FIXED = [ 'post_type', 'page' ];

function pageUrl() {
	return (
		( window.varconvBoot && window.varconvBoot.pageUrl ) ||
		window.location.pathname
	);
}

/** The app's query as an object of strings, without empty values. */
export function getQuery() {
	const query = {};
	new URLSearchParams( window.location.search ).forEach( ( value, key ) => {
		if ( ! FIXED.includes( key ) && '' !== value ) {
			query[ key ] = value;
		}
	} );
	return query;
}

/**
 * The page's URL for a query; empty and missing values are left out.
 */
export function urlFor( query = {} ) {
	const clean = {};
	Object.keys( query ).forEach( ( key ) => {
		if (
			'' !== query[ key ] &&
			null !== query[ key ] &&
			undefined !== query[ key ]
		) {
			clean[ key ] = query[ key ];
		}
	} );
	return addQueryArgs( pageUrl(), clean );
}

/**
 * Shows the page for a query. The whole query is replaced, so pass what should stay.
 */
export function navigate( query = {}, { replace = false } = {} ) {
	const url = urlFor( query );
	if ( replace ) {
		window.history.replaceState( null, '', url );
	} else {
		window.history.pushState( null, '', url );
	}
	listeners.forEach( ( listener ) => listener() );
}

/** The query, kept current as the admin moves around. */
export function useQuery() {
	const [ query, setQuery ] = useState( getQuery );
	useEffect( () => {
		const update = () => setQuery( getQuery() );
		listeners.add( update );
		window.addEventListener( 'popstate', update );
		return () => {
			listeners.delete( update );
			window.removeEventListener( 'popstate', update );
		};
	}, [] );
	return query;
}
