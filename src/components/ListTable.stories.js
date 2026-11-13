import { useState } from '@wordpress/element';
import Badge from './Badge';
import ListTable from './ListTable';
import ReviewCard from './ReviewCard';
import { groups } from './stories-data';

export default { title: 'Lists/ListTable', component: ListTable };

const columns = ( toggle ) => [
	{
		key: 'name',
		label: 'Group',
		primary: true,
		sortable: true,
		render: ( group ) => (
			<button
				type="button"
				className="varconv-row-title"
				onClick={ () => toggle && toggle( group.key ) }
			>
				{ group.name }
			</button>
		),
	},
	{
		key: 'products',
		label: 'Products',
		sortable: true,
		width: '12%',
		render: ( group ) => group.products,
	},
	{
		key: 'sku',
		label: 'SKU family',
		width: '14%',
		render: ( group ) => (
			<code className="varconv-mono">{ group.sku }</code>
		),
	},
	{
		key: 'price',
		label: 'Price',
		width: '16%',
		render: ( group ) => group.price,
	},
	{
		key: 'status',
		label: 'Status',
		width: '16%',
		render: ( group ) =>
			'ready' === group.status ? (
				<Badge kind="ready">Ready</Badge>
			) : (
				<Badge kind="warn">Prices differ</Badge>
			),
	},
];

export const WithCheckboxesAndRowActions = {
	render: () => {
		const [ selected, setSelected ] = useState( [ 'mug' ] );
		const [ sort, setSort ] = useState( { orderby: 'name', order: 'asc' } );
		return (
			<ListTable
				columns={ columns() }
				items={ groups }
				getKey={ ( group ) => group.key }
				getLabel={ ( group ) => group.name }
				selected={ selected }
				onSelect={ setSelected }
				sort={ sort }
				onSort={ ( orderby, order ) => setSort( { orderby, order } ) }
				rowActions={ () => [
					{ key: 'review', label: 'Review', onClick: () => {} },
					{ key: 'create', label: 'Create', onClick: () => {} },
					{
						key: 'skip',
						label: 'Skip, not variants',
						destructive: true,
						onClick: () => {},
					},
				] }
			/>
		);
	},
	parameters: {
		docs: {
			description: {
				story: 'Row actions show when the row is hovered or has keyboard focus.',
			},
		},
	},
};

export const WithAnOpenCard = {
	render: () => {
		const [ open, setOpen ] = useState( [ 'vneck' ] );
		const toggle = ( key ) =>
			setOpen(
				open.includes( key )
					? open.filter( ( k ) => k !== key )
					: [ ...open, key ]
			);
		return (
			<ListTable
				columns={ columns( toggle ) }
				items={ groups }
				getKey={ ( group ) => group.key }
				expanded={ open }
				renderExpanded={ ( group ) => (
					<ReviewCard help="A review card goes here.">
						<p>The products of { group.name }.</p>
					</ReviewCard>
				) }
			/>
		);
	},
};

export const Empty = {
	args: {
		columns: columns(),
		items: [],
		empty: 'No suggested groups. Scan again after adding products.',
	},
};
export const Loading = {
	args: {
		columns: columns(),
		items: groups,
		getKey: ( group ) => group.key,
		loading: true,
	},
};
