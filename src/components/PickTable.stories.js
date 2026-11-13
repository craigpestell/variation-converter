import { TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import PickTable from './PickTable';
import { members } from './stories-data';

export default { title: 'Cards/PickTable', component: PickTable };

const useRows = () => {
	const [ rows, setRows ] = useState( members );
	const toggle = ( id, checked ) =>
		setRows( rows.map( ( r ) => ( r.id === id ? { ...r, checked } : r ) ) );
	const toggleAll = ( checked ) =>
		setRows( rows.map( ( r ) => ( { ...r, checked } ) ) );
	const move = ( from, to ) => {
		const next = [ ...rows ];
		next.splice( to, 0, next.splice( from, 1 )[ 0 ] );
		setRows( next );
	};
	const setLabel = ( id, label ) =>
		setRows( rows.map( ( r ) => ( r.id === id ? { ...r, label } : r ) ) );
	return { rows, toggle, toggleAll, move, setLabel };
};

const columns = ( setLabel ) => [
	{
		key: 'label',
		label: 'Size',
		render: ( row ) => (
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ `Size for ${ row.sku }` }
				hideLabelFromVision
				value={ row.label }
				onChange={ ( label ) => setLabel( row.id, label ) }
			/>
		),
	},
	{
		key: 'sku',
		label: 'SKU',
		render: ( row ) => <code className="varconv-mono">{ row.sku }</code>,
	},
	{ key: 'name', label: 'Current title', render: ( row ) => row.name },
	{ key: 'price', label: 'Price', render: ( row ) => row.price },
];

export const Reorderable = {
	render: () => {
		const { rows, toggle, toggleAll, move, setLabel } = useRows();
		return (
			<PickTable
				columns={ columns( setLabel ) }
				rows={ rows }
				onToggle={ toggle }
				onToggleAll={ toggleAll }
				onMove={ move }
			/>
		);
	},
	parameters: {
		docs: {
			description: {
				story: 'Drag a row by its handle, or focus the handle and use the up and down buttons.',
			},
		},
	},
};
export const FixedOrder = {
	render: () => {
		const { rows, toggle, toggleAll, setLabel } = useRows();
		return (
			<PickTable
				columns={ columns( setLabel ) }
				rows={ rows }
				onToggle={ toggle }
				onToggleAll={ toggleAll }
			/>
		);
	},
};
