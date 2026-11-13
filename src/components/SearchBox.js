import { Button, SearchControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';

/**
 * A small search beside a list's views, with its button: "Search products", "Search history".
 */
export default function SearchBox( { label, value = '', onSearch } ) {
	const [ text, setText ] = useState( value );
	useEffect( () => setText( value ), [ value ] );
	return (
		<form
			className="varconv-search-box"
			role="search"
			onSubmit={ ( event ) => {
				event.preventDefault();
				onSearch( text.trim() );
			} }
		>
			<SearchControl
				__nextHasNoMarginBottom
				size="compact"
				label={ label }
				hideLabelFromVision
				placeholder=""
				value={ text }
				onChange={ ( next ) => {
					setText( next );
					// The field's own clear button searches for nothing right away.
					if ( '' === next && value ) {
						onSearch( '' );
					}
				} }
			/>
			<Button variant="secondary" type="submit" size="compact">
				{ label }
			</Button>
		</form>
	);
}
