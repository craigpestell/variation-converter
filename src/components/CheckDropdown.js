import { Button, CheckboxControl, Dropdown } from '@wordpress/components';

/**
 * A button that opens a short list of checkboxes, for a filter that takes several values. The button says what is
 * picked: "All warnings", "Only: Prices differ", "Only 2 warnings".
 */
export default function CheckDropdown( {
	label,
	legend,
	options,
	value = [],
	onChange,
} ) {
	return (
		<Dropdown
			className="varconv-check-dropdown"
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ onToggle }
					aria-expanded={ isOpen }
				>
					{ label } <span aria-hidden="true">▾</span>
				</Button>
			) }
			renderContent={ () => (
				<fieldset className="varconv-check-dropdown__list">
					<legend>{ legend }</legend>
					{ options.map( ( option ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ option.value }
							label={ option.label }
							checked={ value.includes( option.value ) }
							onChange={ ( on ) =>
								onChange(
									on
										? [ ...value, option.value ]
										: value.filter(
												( v ) => v !== option.value
											)
								)
							}
						/>
					) ) }
				</fieldset>
			) }
		/>
	);
}
