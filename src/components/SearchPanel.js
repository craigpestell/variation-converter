import { Button, SearchControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * A search that leads a page: a heading, a wide field with its Search button, and a line of help under it.
 */
export default function SearchPanel( {
	label,
	placeholder,
	value = '',
	onSearch,
	help,
	clearLabel,
} ) {
	const [ text, setText ] = useState( value );
	useEffect( () => setText( value ), [ value ] );
	return (
		<form
			className="varconv-search-panel"
			role="search"
			onSubmit={ ( event ) => {
				event.preventDefault();
				onSearch( text.trim() );
			} }
		>
			<div className="varconv-search-panel__label">{ label }</div>
			<div className="varconv-search-panel__row">
				<SearchControl
					__nextHasNoMarginBottom
					label={ label }
					hideLabelFromVision
					placeholder={ placeholder }
					value={ text }
					onChange={ setText }
				/>
				<Button variant="primary" type="submit" __next40pxDefaultSize>
					{ __( 'Search', 'variation-converter-for-woocommerce' ) }
				</Button>
				{ value && (
					<Button
						variant="link"
						onClick={ () => {
							setText( '' );
							onSearch( '' );
						} }
					>
						{ clearLabel ||
							__(
								'Clear search',
								'variation-converter-for-woocommerce'
							) }
					</Button>
				) }
			</div>
			{ help && <p className="varconv-search-panel__help">{ help }</p> }
		</form>
	);
}
