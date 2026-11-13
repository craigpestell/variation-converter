import { useState } from '@wordpress/element';
import CheckDropdown from './CheckDropdown';

export default { title: 'Small parts/CheckDropdown', component: CheckDropdown };

const options = [
	{ value: 'price', label: 'Prices differ' },
	{ value: 'cats', label: 'Categories differ' },
	{ value: 'dupes', label: 'Duplicate products' },
];

export const WarningsFilter = {
	render: () => {
		const [ value, setValue ] = useState( [] );
		let label = 'All warnings';
		if ( 1 === value.length ) {
			label =
				'Only: ' +
				options.find( ( o ) => o.value === value[ 0 ] ).label;
		} else if ( value.length > 1 ) {
			label = `Only ${ value.length } warnings`;
		}
		return (
			<CheckDropdown
				label={ label }
				legend="Show groups with only these warnings"
				options={ options }
				value={ value }
				onChange={ setValue }
			/>
		);
	},
};
