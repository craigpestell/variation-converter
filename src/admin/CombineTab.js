import { Button, Notice, SelectControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Badge,
	BulkActions,
	CheckDropdown,
	ListTable,
	Pagination,
	ProductName,
	SearchPanel,
	SelectAllBanner,
	Toolbar,
	Views,
} from '../components';
import { post, errorMessage, useLoad } from './api';
import { useApp } from './context';
import GroupCard, { makeDraft, priceRange } from './GroupCard';
import { navigate } from './router';

const FILTERS = [ 'view', 's', 'brand', 'min', 'warn', 'orderby', 'order' ];

/**
 * The list's filters from the URL, without the page number or the open card.
 */
export function filtersOf( query ) {
	const filters = {};
	FILTERS.forEach( ( key ) => {
		if ( query[ key ] ) {
			filters[ key ] = query[ key ];
		}
	} );
	return filters;
}

function warningOptions() {
	return [
		{
			value: 'noprice',
			label: __( 'No price', 'variation-converter-for-woocommerce' ),
		},
		{
			value: 'price',
			label: __( 'Prices differ', 'variation-converter-for-woocommerce' ),
		},
		{
			value: 'cats',
			label: __(
				'Categories differ',
				'variation-converter-for-woocommerce'
			),
		},
		{
			value: 'label',
			label: __(
				'Missing or repeated labels',
				'variation-converter-for-woocommerce'
			),
		},
		{
			value: 'long',
			label: __( 'Long labels', 'variation-converter-for-woocommerce' ),
		},
		{
			value: 'dupes',
			label: __(
				'Duplicate products',
				'variation-converter-for-woocommerce'
			),
		},
	];
}

/**
 * The Combine tab: search first, then the scan's suggested groups as a list. A group's row opens its review card.
 * Checked groups (and, after a search, products in no group) can be combined into one product, created one product
 * per group, added to a product already combined, or skipped.
 */
export default function CombineTab( { query } ) {
	const { version, notify, changed, followJob, jobRunning, setCombineCount } =
		useApp();
	const filters = filtersOf( query );
	const { data, loading, error } = useLoad(
		'/combine',
		{ ...filters, paged: query.paged || '', open: query.open || '' },
		version
	);
	const [ selected, setSelected ] = useState( [] );
	const [ allMatching, setAllMatching ] = useState( false );
	const [ loose, setLoose ] = useState( [] );
	const [ open, setOpen ] = useState( query.open ? [ query.open ] : [] );
	const [ drafts, setDrafts ] = useState( {} );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		if ( query.open ) {
			setOpen( ( keys ) =>
				keys.includes( query.open ) ? keys : [ ...keys, query.open ]
			);
		}
	}, [ query.open ] );
	const total = data ? data.total : 0;
	const all = data ? data.counts[ '' ] : null;
	const scanned = data ? data.scanned : '';
	useEffect( () => {
		if ( null !== all ) {
			setCombineCount( all, scanned );
		}
	}, [ all, scanned, setCombineCount ] );
	// A different view, search or filter is a different list: what was checked no longer applies.
	const filterKey = JSON.stringify( filters );
	useEffect( () => {
		setSelected( [] );
		setAllMatching( false );
		setLoose( [] );
		setMessage( '' );
	}, [ filterKey ] );

	if ( ! data ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<ListTable columns={ [] } items={ [] } loading />
		);
	}

	const skipped = 'skipped' === filters.view;
	const go = ( patch, keep = {} ) =>
		navigate( { ...filters, ...keep, ...patch } );
	const draftOf = ( group ) => drafts[ group.key ] || makeDraft( group );
	const toggle = ( key, on ) =>
		setOpen( ( keys ) => {
			const isOpen = keys.includes( key );
			const next = undefined === on ? ! isOpen : on;
			if ( next === isOpen ) {
				return keys;
			}
			return next ? [ ...keys, key ] : keys.filter( ( k ) => k !== key );
		} );
	const forget = ( key ) =>
		setDrafts( ( d ) => {
			const next = { ...d };
			delete next[ key ];
			return next;
		} );
	const fail = ( e ) => {
		setBusy( false );
		setMessage( errorMessage( e ) );
	};
	const picked = () => ( {
		keys: selected,
		all: allMatching,
		filters,
	} );
	const clearPicks = () => {
		setSelected( [] );
		setAllMatching( false );
		setLoose( [] );
	};

	// Skip, restore, or create a group as suggested: its row's actions and the Bulk actions menu.
	const act = ( action, keys ) => {
		setBusy( true );
		setMessage( '' );
		post( '/combine/bulk', {
			action,
			...( keys ? { keys } : picked() ),
		} ).then( ( answer ) => {
			setBusy( false );
			if ( answer.job ) {
				followJob( answer.job );
			}
			if ( answer.notice ) {
				notify( answer.notice );
			}
			if ( answer.open ) {
				toggle( answer.open, true );
			}
			clearPicks();
			changed();
		}, fail );
	};
	// "Combine into one product" and "Add to a combined product": the server says which screen the picks lead to.
	const pick = ( action ) => {
		const groups = allMatching ? data.keys.length : selected.length;
		if ( groups + loose.length < 1 ) {
			setMessage(
				__(
					'Tick the groups and products to combine first.',
					'variation-converter-for-woocommerce'
				)
			);
			return;
		}
		setBusy( true );
		setMessage( '' );
		post( '/combine/pick', { action, ...picked(), ids: loose } ).then(
			( answer ) => {
				setBusy( false );
				if ( answer.keep ) {
					toggle( answer.query.open, true );
					return;
				}
				navigate( { ...answer.query, ...filtersOf( filters ) } );
			},
			fail
		);
	};
	const bulk = ( action ) => {
		if ( ! action ) {
			setMessage(
				__(
					'Choose a bulk action first.',
					'variation-converter-for-woocommerce'
				)
			);
			return;
		}
		if ( ! allMatching && ! selected.length ) {
			setMessage(
				__(
					'Select at least one group first.',
					'variation-converter-for-woocommerce'
				)
			);
			return;
		}
		act( action );
	};

	const groupCount = allMatching ? data.keys.length : selected.length;
	const parts = [];
	if ( groupCount ) {
		parts.push(
			sprintf(
				/* translators: %d: number of groups checked. */
				_n(
					'%d group',
					'%d groups',
					groupCount,
					'variation-converter-for-woocommerce'
				),
				groupCount
			)
		);
	}
	if ( loose.length ) {
		parts.push(
			sprintf(
				/* translators: %d: number of products checked. */
				_n(
					'%d product',
					'%d products',
					loose.length,
					'variation-converter-for-woocommerce'
				),
				loose.length
			)
		);
	}
	const combineButtons = ! skipped && (
		<div className="varconv-combine-buttons">
			<Button
				variant="primary"
				__next40pxDefaultSize
				disabled={ busy || jobRunning }
				onClick={ () => pick( 'combine' ) }
			>
				{ __(
					'Combine into one product',
					'variation-converter-for-woocommerce'
				) }
			</Button>
			<Button
				variant="secondary"
				__next40pxDefaultSize
				disabled={ busy || jobRunning }
				onClick={ () => bulk( 'create' ) }
			>
				{ __(
					'Create a product for each group',
					'variation-converter-for-woocommerce'
				) }
			</Button>
			<Button
				variant="secondary"
				__next40pxDefaultSize
				disabled={ busy || jobRunning }
				onClick={ () => pick( 'add' ) }
			>
				{ __(
					'Add to a combined product',
					'variation-converter-for-woocommerce'
				) }
			</Button>
			<span className="varconv-muted" aria-live="polite">
				{ parts.length > 0 &&
					sprintf(
						/* translators: %s: what is checked, such as "2 groups, 1 product". */
						__(
							'Selected: %s',
							'variation-converter-for-woocommerce'
						),
						parts.join( ', ' )
					) }
			</span>
		</div>
	);
	const bulkMenu = (
		<BulkActions
			disabled={ busy || jobRunning }
			onApply={ bulk }
			actions={
				skipped
					? [
							{
								value: 'restore',
								label: __(
									'Restore to the list',
									'variation-converter-for-woocommerce'
								),
							},
						]
					: [
							{
								value: 'skip',
								label: __(
									'Skip, not variants',
									'variation-converter-for-woocommerce'
								),
							},
						]
			}
		/>
	);
	const pagination = (
		<Pagination
			page={ data.page }
			pages={ data.pages }
			onChange={ ( paged ) => go( { paged: paged > 1 ? paged : '' } ) }
			label={ sprintf(
				/* translators: 1: number of groups, 2: number of products in them. */
				_n(
					'%1$s group, %2$s products',
					'%1$s groups, %2$s products',
					total,
					'variation-converter-for-woocommerce'
				),
				total.toLocaleString(),
				data.products.toLocaleString()
			) }
		/>
	);

	const warn = filters.warn ? filters.warn.split( ',' ) : [];
	const warnings = warningOptions();
	let warnLabel = __( 'All warnings', 'variation-converter-for-woocommerce' );
	if ( 1 === warn.length ) {
		warnLabel = sprintf(
			/* translators: %s: the one warning picked in the filter, such as "Prices differ". */
			__( 'Only: %s', 'variation-converter-for-woocommerce' ),
			( warnings.find( ( w ) => w.value === warn[ 0 ] ) || {} ).label
		);
	} else if ( warn.length > 1 ) {
		warnLabel = sprintf(
			/* translators: %d: number of warnings picked in the filter. */
			__( 'Only %d warnings', 'variation-converter-for-woocommerce' ),
			warn.length
		);
	}

	const pageKeys = data.groups.map( ( g ) => g.key );
	const pageAllChecked =
		pageKeys.length > 0 &&
		pageKeys.every( ( k ) => selected.includes( k ) );
	const columns = [
		{
			key: 'name',
			label: __( 'Group', 'variation-converter-for-woocommerce' ),
			primary: true,
			sortable: true,
			render: ( group ) => (
				<>
					<button
						type="button"
						className="varconv-row-title"
						aria-expanded={ open.includes( group.key ) }
						onClick={ () => toggle( group.key ) }
					>
						<span aria-hidden="true">
							{ open.includes( group.key ) ? '▾' : '▸' }
						</span>{ ' ' }
						{ group.name }
					</button>
					{ group.brand && (
						<span className="varconv-row-sub">{ group.brand }</span>
					) }
				</>
			),
		},
		{
			key: 'size',
			label: __( 'Products', 'variation-converter-for-woocommerce' ),
			width: 110,
			sortable: true,
			firstOrder: 'desc',
			render: ( group ) => {
				const members = draftOf( group ).members;
				const checked = members.filter( ( m ) => m.checked ).length;
				return checked === members.length
					? members.length
					: sprintf(
							/* translators: 1: products checked, 2: products in the group. */
							__(
								'%1$d of %2$d',
								'variation-converter-for-woocommerce'
							),
							checked,
							members.length
						);
			},
		},
		{
			key: 'sku',
			label: __( 'SKU family', 'variation-converter-for-woocommerce' ),
			width: 130,
			render: ( group ) => (
				<span className="varconv-mono">{ group.sku }</span>
			),
		},
		{
			key: 'price',
			label: __( 'Price', 'variation-converter-for-woocommerce' ),
			width: 150,
			sortable: true,
			render: ( group ) => priceRange( draftOf( group ).members ),
		},
		{
			key: 'attr',
			label: __( 'Attribute', 'variation-converter-for-woocommerce' ),
			width: 110,
			render: ( group ) => group.attribute,
		},
		{
			key: 'status',
			label: __( 'Status', 'variation-converter-for-woocommerce' ),
			width: 190,
			render: ( group ) => {
				if ( skipped ) {
					return (
						<Badge>
							{ __(
								'Skipped',
								'variation-converter-for-woocommerce'
							) }
						</Badge>
					);
				}
				if ( ! group.badges.length ) {
					return (
						<Badge
							kind="ready"
							title={ __(
								'Groups with no warnings. They can be created as suggested.',
								'variation-converter-for-woocommerce'
							) }
						>
							{ __(
								'Ready',
								'variation-converter-for-woocommerce'
							) }
						</Badge>
					);
				}
				return group.badges.map( ( badge ) => (
					<Badge key={ badge.kind } kind="warn">
						{ badge.label }
					</Badge>
				) );
			},
		},
	];

	return (
		<>
			<SearchPanel
				label={ __(
					'Find the products to combine',
					'variation-converter-for-woocommerce'
				) }
				placeholder={ __(
					'Product name or SKU, for example Classic Tee',
					'variation-converter-for-woocommerce'
				) }
				value={ filters.s || '' }
				onSearch={ ( s ) => go( { s } ) }
				help={ __(
					'Tick the groups and products that are one product, then click Combine into one product. Nothing changes until you create it, and History can undo every change.',
					'variation-converter-for-woocommerce'
				) }
			/>
			<h2 className="varconv-list-title">
				{ filters.s
					? sprintf(
							/* translators: %s: what was searched for. */
							__(
								'Suggested groups matching "%s"',
								'variation-converter-for-woocommerce'
							),
							filters.s
						)
					: __(
							'Suggested groups',
							'variation-converter-for-woocommerce'
						) }
			</h2>
			<Views
				current={ filters.view || '' }
				onChange={ ( view ) =>
					navigate( { view, s: filters.s || '' } )
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
							'Ready',
							'variation-converter-for-woocommerce'
						),
						count: data.counts.ready,
						tip: __(
							'Groups with no warnings. They can be created as suggested.',
							'variation-converter-for-woocommerce'
						),
					},
					{
						key: 'review',
						label: __(
							'Needs review',
							'variation-converter-for-woocommerce'
						),
						count: data.counts.review,
						tip: __(
							'Groups with a warning to check first, such as different prices or missing labels.',
							'variation-converter-for-woocommerce'
						),
					},
					{
						key: 'skipped',
						label: __(
							'Skipped',
							'variation-converter-for-woocommerce'
						),
						count: data.counts.skipped,
						tip: __(
							'Groups you marked "Skip, not variants". They can be restored.',
							'variation-converter-for-woocommerce'
						),
					},
				] }
			/>
			<Toolbar end={ pagination }>
				{ combineButtons }
				{ bulkMenu }
				<div className="varconv-filters">
					{ data.brands.length > 0 && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Filter by brand',
								'variation-converter-for-woocommerce'
							) }
							hideLabelFromVision
							value={
								data.brands.find(
									( b ) =>
										b.toLowerCase() ===
										( filters.brand || '' ).toLowerCase()
								) || ''
							}
							onChange={ ( brand ) => go( { brand } ) }
							options={ [
								{
									value: '',
									label: __(
										'All brands',
										'variation-converter-for-woocommerce'
									),
								},
								...data.brands.map( ( b ) => ( {
									value: b,
									label: b,
								} ) ),
							] }
						/>
					) }
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Filter by group size',
							'variation-converter-for-woocommerce'
						) }
						hideLabelFromVision
						value={ filters.min || '2' }
						onChange={ ( min ) =>
							go( { min: '2' === min ? '' : min } )
						}
						options={ [
							{
								value: '2',
								label: __(
									'All sizes',
									'variation-converter-for-woocommerce'
								),
							},
							...[ 3, 4, 5 ].map( ( n ) => ( {
								value: String( n ),
								label: sprintf(
									/* translators: %d: smallest number of products in a group. */
									__(
										'%d+ products',
										'variation-converter-for-woocommerce'
									),
									n
								),
							} ) ),
						] }
					/>
					<CheckDropdown
						label={ warnLabel }
						legend={ __(
							'Show groups with only these warnings',
							'variation-converter-for-woocommerce'
						) }
						options={ warnings }
						value={ warn }
						onChange={ ( next ) =>
							go( { warn: next.join( ',' ) } )
						}
					/>
					{ ( filters.brand || filters.min || filters.warn ) && (
						<Button
							variant="link"
							onClick={ () =>
								go( { brand: '', min: '', warn: '' } )
							}
						>
							{ __(
								'Clear filters',
								'variation-converter-for-woocommerce'
							) }
						</Button>
					) }
				</div>
			</Toolbar>
			{ message && (
				<Notice
					className="varconv-notice"
					status="warning"
					onRemove={ () => setMessage( '' ) }
				>
					{ message }
				</Notice>
			) }
			{ ! skipped &&
				total > data.groups.length &&
				( pageAllChecked || allMatching ) && (
					<SelectAllBanner
						all={ allMatching }
						pageText={ sprintf(
							/* translators: %d: number of groups on this page. */
							__(
								'All %d groups on this page are selected.',
								'variation-converter-for-woocommerce'
							),
							pageKeys.length
						) }
						selectLabel={ sprintf(
							/* translators: %d: number of groups the view and filters show. */
							__(
								'Select all %d groups that match',
								'variation-converter-for-woocommerce'
							),
							total
						) }
						allText={ sprintf(
							/* translators: %d: number of groups the view and filters show. */
							__(
								'All %d groups that match are selected, on every page.',
								'variation-converter-for-woocommerce'
							),
							total
						) }
						clearLabel={ __(
							'Clear selection',
							'variation-converter-for-woocommerce'
						) }
						onSelectAll={ () => setAllMatching( true ) }
						onClear={ clearPicks }
					/>
				) }
			<ListTable
				className="varconv-groups"
				loading={ loading }
				columns={ columns }
				items={ data.groups }
				getKey={ ( group ) => group.key }
				getLabel={ ( group ) => group.name }
				selected={ selected }
				onSelect={ ( keys ) => {
					setSelected( keys );
					setAllMatching( false );
				} }
				sort={ {
					orderby: filters.orderby || 'name',
					order:
						filters.order ||
						( 'size' === filters.orderby ? 'desc' : 'asc' ),
				} }
				onSort={ ( { orderby, order } ) =>
					go( { orderby: 'name' === orderby ? '' : orderby, order } )
				}
				expanded={ open }
				rowActions={ ( group ) =>
					skipped
						? [
								{
									key: 'review',
									label: __(
										'Review',
										'variation-converter-for-woocommerce'
									),
									onClick: () => toggle( group.key ),
								},
								{
									key: 'restore',
									label: __(
										'Restore to the list',
										'variation-converter-for-woocommerce'
									),
									onClick: () =>
										act( 'restore', [ group.key ] ),
								},
							]
						: [
								{
									key: 'review',
									label: __(
										'Review',
										'variation-converter-for-woocommerce'
									),
									onClick: () => toggle( group.key ),
								},
								{
									key: 'create',
									label: __(
										'Create',
										'variation-converter-for-woocommerce'
									),
									onClick: () =>
										act( 'create_one', [ group.key ] ),
								},
								{
									key: 'skip',
									label: __(
										'Skip, not variants',
										'variation-converter-for-woocommerce'
									),
									destructive: true,
									onClick: () => act( 'skip', [ group.key ] ),
								},
							]
				}
				renderExpanded={ ( group ) => (
					<GroupCard
						group={ group }
						draft={ draftOf( group ) }
						onChange={ ( draft ) =>
							setDrafts( ( d ) => ( {
								...d,
								[ group.key ]: draft,
							} ) )
						}
						onCancel={ () => toggle( group.key, false ) }
						onSkip={
							skipped
								? undefined
								: () => act( 'skip', [ group.key ] )
						}
						onCreated={ ( answer ) => {
							forget( group.key );
							toggle( group.key, false );
							notify( answer.notice );
							// Part of a group: open what is left of it, since its warnings and SKU buttons changed.
							if ( ! answer.whole && answer.next ) {
								toggle( answer.next, true );
								go( { open: answer.next } );
							} else if ( query.open ) {
								go( {} );
							}
							changed();
						} }
					/>
				) }
				empty={
					<>
						{ data.empty }{ ' ' }
						{ data.filtered && ! filters.s && (
							<Button
								variant="link"
								onClick={ () =>
									navigate( { view: filters.view || '' } )
								}
							>
								{ __(
									'Clear filters',
									'variation-converter-for-woocommerce'
								) }
							</Button>
						) }
					</>
				}
			/>
			<Toolbar end={ pagination }>
				{ ! ( filters.s && data.loose.total ) && combineButtons }
				{ bulkMenu }
			</Toolbar>
			{ ! skipped && filters.s && (
				<>
					<h2 className="varconv-list-title">
						{ __(
							'Products not in a group',
							'variation-converter-for-woocommerce'
						) }{ ' ' }
						<span className="varconv-muted">
							({ data.loose.total })
						</span>
					</h2>
					{ ! data.loose.total ? (
						<p className="varconv-help">
							{ sprintf(
								/* translators: %s: what was searched for. */
								__(
									'Every simple product matching "%s" is in a group above. Only simple products with a SKU, shown in the catalog, are listed here; to add one without a SKU to a combined product, use Add to a combined product in Products > All Products.',
									'variation-converter-for-woocommerce'
								),
								filters.s
							) }
						</p>
					) : (
						<>
							<p className="varconv-help">
								{ __(
									'The scan did not group these. Tick them to combine them with each other or with the groups above, or to add them to a product you already combined. A product picked with two or more groups becomes a group of its own.',
									'variation-converter-for-woocommerce'
								) }
							</p>
							<ListTable
								className="varconv-loose"
								items={ data.loose.items }
								getLabel={ ( p ) => p.title }
								selected={ loose }
								onSelect={ setLoose }
								columns={ [
									{
										key: 'product',
										label: __(
											'Product',
											'variation-converter-for-woocommerce'
										),
										primary: true,
										render: ( p ) => (
											<ProductName
												name={ p.title }
												href={ p.editUrl }
												state={ p.state }
											/>
										),
									},
									{
										key: 'sku',
										label: __(
											'SKU',
											'variation-converter-for-woocommerce'
										),
										width: 160,
										render: ( p ) => (
											<span className="varconv-mono">
												{ p.sku }
											</span>
										),
									},
									{
										key: 'price',
										label: __(
											'Price',
											'variation-converter-for-woocommerce'
										),
										width: 140,
										render: ( p ) => p.priceText,
									},
								] }
							/>
							{ data.loose.total > data.loose.items.length && (
								<p className="varconv-help">
									{ sprintf(
										/* translators: 1: products listed, 2: products that match. */
										__(
											'Showing the first %1$d of %2$d products. Search for something more specific to see the rest.',
											'variation-converter-for-woocommerce'
										),
										data.loose.items.length,
										data.loose.total
									) }
								</p>
							) }
							<Toolbar>{ combineButtons }</Toolbar>
						</>
					) }
				</>
			) }
		</>
	);
}
