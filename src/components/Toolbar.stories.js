import { Button } from '@wordpress/components';
import BulkActions from './BulkActions';
import Pagination from './Pagination';
import Toolbar from './Toolbar';

export default { title: 'Lists/Toolbar', component: Toolbar };

export const WithActionsAndCount = {
	render: () => (
		<Toolbar
			end={
				<Pagination
					page={ 1 }
					pages={ 3 }
					label="54 groups, 210 products"
					onChange={ () => {} }
				/>
			}
		>
			<Button variant="primary" __next40pxDefaultSize>
				Combine into one product
			</Button>
			<BulkActions
				actions={ [ { value: 'skip', label: 'Skip, not variants' } ] }
				onApply={ () => {} }
			/>
		</Toolbar>
	),
};
