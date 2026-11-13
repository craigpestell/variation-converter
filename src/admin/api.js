/*
 * Requests to the plugin's REST routes (includes/rest.php). apiFetch sends WordPress's REST nonce with each one.
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const BASE = '/varconv/v1';

export function get( path, query = {} ) {
	return apiFetch( { path: addQueryArgs( BASE + path, query ) } );
}

export function post( path, data = {} ) {
	return apiFetch( { path: BASE + path, method: 'POST', data } );
}

/**
 * What went wrong, in words to show.
 */
export function errorMessage( error ) {
	return (
		( error && error.message ) ||
		__(
			'Something went wrong. Reload the page and try again.',
			'variation-converter-for-woocommerce'
		)
	);
}

/**
 * Loads a route's data, and again whenever the query or `version` changes. The data of the last load stays while the
 * next one runs, so a list does not blink when it is filtered or paged.
 */
export function useLoad( path, query = {}, version = 0 ) {
	const [ state, setState ] = useState( {
		data: null,
		loading: true,
		error: '',
	} );
	const [ count, setCount ] = useState( 0 );
	const latest = useRef( 0 );
	const key = JSON.stringify( query );
	useEffect( () => {
		if ( ! path ) {
			return;
		}
		const mine = ++latest.current;
		setState( ( s ) => ( { ...s, loading: true, error: '' } ) );
		get( path, JSON.parse( key ) ).then(
			( data ) => {
				if ( mine === latest.current ) {
					setState( { data, loading: false, error: '' } );
				}
			},
			( error ) => {
				if ( mine === latest.current ) {
					setState( {
						data: null,
						loading: false,
						error: errorMessage( error ),
					} );
				}
			}
		);
	}, [ path, key, version, count ] );
	const reload = useCallback( () => setCount( ( n ) => n + 1 ), [] );
	return { ...state, reload };
}
