import { useState } from '@wordpress/element';
import TabNav from './TabNav';

export default { title: 'Page/TabNav', component: TabNav };

export const Default = {
	render: () => {
		const [ current, setCurrent ] = useState( 'combine' );
		return (
			<TabNav
				current={ current }
				onChange={ setCurrent }
				tabs={ [
					{
						key: 'combine',
						label: 'Combine',
						count: 12,
						href: '#combine',
					},
					{ key: 'split', label: 'Split', href: '#split' },
					{
						key: 'history',
						label: 'History',
						count: 3,
						href: '#history',
					},
				] }
			/>
		);
	},
};
