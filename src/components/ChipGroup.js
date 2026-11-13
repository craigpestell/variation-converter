/**
 * A row of small buttons of which one is on, for picking by a prefix: "Select by SKU: All | TEE1 (4) | TEE2 (3)".
 */
export default function ChipGroup( { label, options, value, onChange } ) {
	return (
		<span className="varconv-chips" role="group" aria-label={ label }>
			<span className="varconv-chips__label">{ label }</span>
			{ options.map( ( option ) => (
				<button
					key={ option.value }
					type="button"
					className="varconv-chips__chip"
					aria-pressed={ option.value === value }
					aria-label={ option.ariaLabel }
					onClick={ () => onChange( option.value ) }
				>
					{ option.label }
				</button>
			) ) }
		</span>
	);
}
