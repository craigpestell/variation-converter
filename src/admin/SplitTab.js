import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	ListTable,
	Pagination,
	SearchPanel,
	Toolbar,
	Views,
} from '../components';
import { useLoad } from './api';
import { useApp } from './context';
import { navigate, urlFor } from './router';
import SplitCard from './SplitCard';

/**
 * The Split tab: variable products as a list. A product's row opens its card, to split it into one simple product
 * per variation or to make just some variations simple.
 */
export default function SplitTab( { query } ) {
	const { version } = useApp();
	const filters = { tab: 'split' };
	[ 'view', 's', 'orderby', 'order' ].forEach( ( key ) => {
		if ( query[ key ] ) {
			filters[ key ] = query[ key ];
		}
	} );
	const { data, loading, error } = useLoad(
		'/split',
		{ ...filters, paged: query.paged || '' },
		version
	);
	const open = parseInt( query.product, 10 ) || 0;
	const go = ( patch ) =>
		navigate( { ...filters, paged: query.paged || '', ...patch } );
	const close = () => go( { product: '' } );

	const search = (
		<SearchPanel
			label={ __(
				'Find the variable products to split',
				'variation-converter-for-woocommerce'
			) }
			placeholder={ __(
				'Product name or SKU, for example Classic Tee',
				'variation-converter-for-woocommerce'
			) }
			value={ filters.s || '' }
			onSearch={ ( s ) => go( { s, paged: '', product: '' } ) }
			help={ __(
				'Turn a variable product into one simple product per variation, or make just the variations you pick into simple products and keep the rest variable. Nothing is deleted, and Undo in History puts it back.',
				'variation-converter-for-woocommerce'
			) }
		/>
	);
	if ( ! data ) {
		return (
			<>
				{ search }
				{ error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : (
					<ListTable columns={ [] } items={ [] } loading />
				) }
			</>
		);
	}
	const onPage = data.items.some( ( item ) => item.id === open );
	const pagination = (
		<Pagination
			page={ data.page }
			pages={ data.pages }
			label={ data.label }
			onChange={ ( paged ) =>
				go( { paged: paged > 1 ? paged : '', product: '' } )
			}
		/>
	);
	return (
		<>
			{ search }
			<Views
				current={ filters.view || '' }
				onChange={ ( view ) =>
					navigate( { tab: 'split', view, s: filters.s || '' } )
				}
				views={ [
					{
						key: '',
						label: __(
							'All',
							'variation-converter-for-woocommerce'
						),
						count: data.counts[ '' ],
					},
					{
						key: 'ready',
						label: __(
							'Can be split',
							'variation-converter-for-woocommerce'
						),
						count: data.counts.ready,
					},
					{
						key: 'combined',
						label: __(
							'Combined here',
							'variation-converter-for-woocommerce'
						),
						count: data.counts.combined,
					},
				] }
			/>
			{ open > 0 && ! onPage && (
				// Opened from Products > All Products but not on this page of the list.
				<SplitCard id={ open } alone onClose={ close } />
			) }
			<Toolbar end={ pagination } />
			<ListTable
				className="varconv-split"
				loading={ loading }
				items={ data.items }
				empty={ data.empty }
				sort={ {
					orderby: filters.orderby || '',
					order: filters.order || 'desc',
				} }
				onSort={ ( { orderby, order } ) =>
					go( { orderby, order, paged: '' } )
				}
				expanded={ open ? [ open ] : [] }
				renderExpanded={ ( item ) => (
					<SplitCard id={ item.id } onClose={ close } />
				) }
				rowActions={ ( item ) => [
					...( item.combined
						? []
						: [
								{
									key: 'split',
									label: __(
										'Split',
										'variation-converter-for-woocommerce'
									),
									onClick: () => go( { product: item.id } ),
								},
							] ),
					{
						key: 'make-simple',
						label: __(
							'Make variations simple',
							'variation-converter-for-woocommerce'
						),
						onClick: () => go( { product: item.id } ),
					},
					{
						key: 'edit',
						label: __(
							'Edit',
							'variation-converter-for-woocommerce'
						),
						href: item.editUrl,
					},
					{
						key: 'view',
						label: __(
							'View',
							'variation-converter-for-woocommerce'
						),
						href: item.viewUrl,
					},
				] }
				columns={ [
					{
						key: 'product',
						label: __(
							'Variable product',
							'variation-converter-for-woocommerce'
						),
						primary: true,
						render: ( item ) => (
							<>
								<button
									type="button"
									className="varconv-row-title"
									aria-expanded={ item.id === open }
									onClick={ () =>
										go( {
											product:
												item.id === open ? '' : item.id,
										} )
									}
								>
									<span aria-hidden="true">
										{ item.id === open ? '▾' : '▸' }
									</span>{ ' ' }
									{ item.name }
								</button>
								{ item.state && (
									<span className="varconv-product-name__state">
										{ ' — ' }
										{ item.state }
									</span>
								) }
							</>
						),
					},
					{
						key: 'sku',
						label: __(
							'SKU',
							'variation-converter-for-woocommerce'
						),
						width: 130,
						render: ( item ) => (
							<span className="varconv-mono">{ item.sku }</span>
						),
					},
					{
						key: 'variations',
						label: __(
							'Variations',
							'variation-converter-for-woocommerce'
						),
						width: 150,
						sortable: true,
						firstOrder: 'desc',
						render: ( item ) => item.variations,
					},
					{
						key: 'price',
						label: __(
							'Price',
							'variation-converter-for-woocommerce'
						),
						width: 150,
						render: ( item ) => item.priceText,
					},
					{
						key: 'note',
						label: __(
							'Note',
							'variation-converter-for-woocommerce'
						),
						width: 230,
						render: ( item ) =>
							item.combined && (
								<span className="varconv-muted">
									{ __(
										'Combined here.',
										'variation-converter-for-woocommerce'
									) }{ ' ' }
									<a
										href={ urlFor( { tab: 'history' } ) }
										onClick={ ( event ) => {
											event.preventDefault();
											navigate( { tab: 'history' } );
										} }
									>
										{ __(
											'Undo in History',
											'variation-converter-for-woocommerce'
										) }
									</a>
								</span>
							),
					},
				] }
			/>
			<Toolbar end={ pagination } />
		</>
	);
}
