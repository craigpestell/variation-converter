import { useState } from '@wordpress/element';
import Views from './Views';

export default { title: 'Lists/Views', component: Views };

export const Default = {
	render: () => {
		const [ current, setCurrent ] = useState( '' );
		return (
			<Views
				label="Groups"
				current={ current }
				onChange={ setCurrent }
				views={ [
					{ key: '', label: 'All', count: 12 },
					{
						key: 'ready',
						label: 'Ready',
						count: 8,
						tip: 'Groups with no warnings.',
					},
					{ key: 'review', label: 'Needs review', count: 4 },
					{ key: 'skipped', label: 'Skipped', count: 0 },
				] }
			/>
		);
	},
};
