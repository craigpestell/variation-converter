import { Button, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * A "Bulk actions" menu with its Apply button, for the rows checked in a list.
 */
export default function BulkActions( { actions, onApply, disabled = false } ) {
	const [ action, setAction ] = useState( '' );
	return (
		<div className="varconv-bulk-actions">
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __(
					'Select bulk action',
					'variation-converter-for-woocommerce'
				) }
				hideLabelFromVision
				value={ action }
				onChange={ setAction }
				options={ [
					{
						value: '',
						label: __(
							'Bulk actions',
							'variation-converter-for-woocommerce'
						),
					},
					...actions,
				] }
			/>
			<Button
				variant="secondary"
				__next40pxDefaultSize
				disabled={ disabled }
				onClick={ () => onApply( action ) }
			>
				{ __( 'Apply', 'variation-converter-for-woocommerce' ) }
			</Button>
		</div>
	);
}
