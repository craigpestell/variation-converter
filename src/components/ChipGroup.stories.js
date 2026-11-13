import { useState } from '@wordpress/element';
import ChipGroup from './ChipGroup';

export default { title: 'Small parts/ChipGroup', component: ChipGroup };

export const SelectBySku = {
	render: ( args ) => {
		const [ value, setValue ] = useState( '' );
		return <ChipGroup { ...args } value={ value } onChange={ setValue } />;
	},
	args: {
		label: 'Select by SKU:',
		options: [
			{ value: '', label: 'All' },
			{ value: 'TEE1', label: 'TEE1 (4)' },
			{ value: 'TEE2', label: 'TEE2 (3)' },
		],
	},
};
