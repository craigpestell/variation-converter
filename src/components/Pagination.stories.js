import { useState } from '@wordpress/element';
import Pagination from './Pagination';

export default { title: 'Lists/Pagination', component: Pagination };

export const OnePage = {
	args: { page: 1, pages: 1, label: '7 groups, 22 products' },
};
export const ManyPages = {
	render: () => {
		const [ page, setPage ] = useState( 2 );
		return (
			<Pagination
				page={ page }
				pages={ 9 }
				label="164 groups, 612 products"
				onChange={ setPage }
			/>
		);
	},
};
