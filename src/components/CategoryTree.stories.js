import { useState } from '@wordpress/element';
import CategoryTree from './CategoryTree';
import { categories } from './stories-data';

export default { title: 'Small parts/CategoryTree', component: CategoryTree };

export const Default = {
	render: () => {
		const [ value, setValue ] = useState( [ 2 ] );
		return (
			<CategoryTree
				categories={ categories }
				value={ value }
				onChange={ setValue }
			/>
		);
	},
};
