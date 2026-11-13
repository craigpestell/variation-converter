import { useState } from '@wordpress/element';
import SearchPanel from './SearchPanel';
import Views from './Views';

export default {
	title: 'Page/SearchPanel',
	component: SearchPanel,
	argTypes: { onSearch: { action: 'search' } },
	parameters: {
		docs: {
			description: {
				component:
					'Every tab (Combine, Split, History) and the Add screen start with this search, in the same place: above the list title and its views. While a search is active, each view count counts only the products the search finds.',
			},
		},
	},
};

const placeholder = 'Product name or SKU, for example Classic Tee';

export const Combine = {
	args: {
		label: 'Find the products to combine',
		placeholder,
		help: 'Tick the groups and products that are one product, then click Combine into one product. Nothing changes until you create it, and History can undo every change.',
	},
};
export const Split = {
	args: {
		label: 'Find the variable products to split',
		placeholder,
		help: 'Turn a variable product into one simple product per variation, or make just the variations you pick into simple products and keep the rest variable. Nothing is deleted, and Undo in History puts it back.',
	},
};
export const History = {
	args: {
		label: 'Find a conversion',
		placeholder,
		help: 'Every conversion made here, newest first. Undo puts the products back as they were.',
	},
};
export const WithSearch = { args: { ...Combine.args, value: 'Barista Syrup' } };

const GROUPS = [
	{ name: 'Barista Syrup 750ml', view: 'ready' },
	{ name: 'Barista Syrup 1L', view: 'review' },
	{ name: 'Classic Tee', view: 'ready' },
	{ name: 'Classic Hoodie', view: 'review' },
	{ name: 'Linen Napkin', view: 'skipped' },
	{ name: 'Barista Sauce', view: 'skipped' },
];

/** The search and the views together: search "barista" and every count follows the results. */
export const WithViewCounts = {
	render: () => {
		const [ s, setS ] = useState( '' );
		const [ view, setView ] = useState( '' );
		const found = GROUPS.filter( ( g ) =>
			g.name.toLowerCase().includes( s.toLowerCase() )
		);
		const count = ( key ) =>
			found.filter( ( g ) =>
				key ? g.view === key : 'skipped' !== g.view
			).length;
		return (
			<>
				<SearchPanel
					{ ...Combine.args }
					value={ s }
					onSearch={ setS }
				/>
				<Views
					label="Groups"
					current={ view }
					onChange={ setView }
					views={ [
						{ key: '', label: 'All', count: count( '' ) },
						{
							key: 'ready',
							label: 'Ready',
							count: count( 'ready' ),
						},
						{
							key: 'review',
							label: 'Needs review',
							count: count( 'review' ),
						},
						{
							key: 'skipped',
							label: 'Skipped',
							count: count( 'skipped' ),
						},
					] }
				/>
			</>
		);
	},
};
