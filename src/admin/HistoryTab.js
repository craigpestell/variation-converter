import { Notice } from '@wordpress/components';
import { RawHTML, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Badge,
	BulkActions,
	ListTable,
	Pagination,
	ProductName,
	SearchPanel,
	SelectAllBanner,
	Toolbar,
	Views,
} from '../components';
import { post, errorMessage, useLoad } from './api';
import { useConfirm } from './confirm';
import { useApp } from './context';
import { navigate } from './router';

/**
 * The History tab: every conversion made here, newest first. Undo puts a conversion's products back as they were;
 * Trash originals moves a combine's hidden simple products to the Trash. Both work on one row, on the checked rows,
 * or on every row of the list.
 */
export default function HistoryTab( { query } ) {
	const { version, notify, changed, followJob, jobRunning } = useApp();
	const filters = { tab: 'history' };
	[ 'view', 's' ].forEach( ( key ) => {
		if ( query[ key ] ) {
			filters[ key ] = query[ key ];
		}
	} );
	const { data, loading, error } = useLoad(
		'/history',
		{ ...filters, paged: query.paged || '' },
		version
	);
	const [ selected, setSelected ] = useState( [] );
	const [ allMatching, setAllMatching ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ ask, dialog ] = useConfirm();
	const listKey = JSON.stringify( [ filters, query.paged, version ] );
	useEffect( () => {
		setSelected( [] );
		setAllMatching( false );
		setMessage( '' );
	}, [ listKey ] );

	const go = ( patch ) => navigate( { ...filters, ...patch } );
	const search = (
		<SearchPanel
			label={ __(
				'Find a conversion',
				'variation-converter-for-woocommerce'
			) }
			placeholder={ __(
				'Product name or SKU, for example Classic Tee',
				'variation-converter-for-woocommerce'
			) }
			value={ filters.s || '' }
			onSearch={ ( s ) => go( { s } ) }
			help={ __(
				'Every conversion made here, newest first. Undo puts the products back as they were.',
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

	const send = ( path, body ) => {
		setBusy( true );
		setMessage( '' );
		post( path, body ).then(
			( answer ) => {
				setBusy( false );
				if ( answer.job ) {
					followJob( answer.job );
				}
				if ( answer.notice ) {
					notify( answer.notice );
				}
				changed();
			},
			( e ) => {
				setBusy( false );
				notify( { status: 'error', text: errorMessage( e ) } );
			}
		);
	};
	const undoOne = async ( item ) => {
		if (
			await ask(
				item.undoConfirm,
				__( 'Undo', 'variation-converter-for-woocommerce' )
			)
		) {
			send( '/history/undo', { ids: [ item.id ] } );
		}
	};
	const trashOne = async ( item ) => {
		if (
			await ask(
				data.trashConfirm,
				__( 'Move to Trash', 'variation-converter-for-woocommerce' )
			)
		) {
			send( '/history/trash', { ids: [ item.id ] } );
		}
	};
	const bulk = async ( action ) => {
		if ( ! action ) {
			setMessage(
				__(
					'Choose a bulk action first.',
					'variation-converter-for-woocommerce'
				)
			);
			return;
		}
		if ( ! selected.length ) {
			setMessage(
				__(
					'Check at least one conversion first.',
					'variation-converter-for-woocommerce'
				)
			);
			return;
		}
		let question =
			'undo' === action
				? __(
						'Undo every checked conversion? Variable products made by combining are deleted and their simple products shown again; simple products made by a split are deleted and the variable product shown again; variations made simple are enabled again.',
						'variation-converter-for-woocommerce'
					)
				: data.trashConfirm;
		if ( allMatching ) {
			question +=
				' ' +
				sprintf(
					/* translators: %s: number of conversions. */
					__(
						'This applies to all %s conversions in the list, on every page.',
						'variation-converter-for-woocommerce'
					),
					data.total.toLocaleString()
				);
		}
		const yes = await ask(
			question,
			'undo' === action
				? __( 'Undo', 'variation-converter-for-woocommerce' )
				: __( 'Move to Trash', 'variation-converter-for-woocommerce' )
		);
		if ( yes ) {
			send( 'undo' === action ? '/history/undo' : '/history/trash', {
				ids: selected,
				all: allMatching,
				view: filters.view || '',
				s: filters.s || '',
			} );
		}
	};

	const pagination = (
		<Pagination
			page={ data.page }
			pages={ data.pages }
			label={ data.label }
			onChange={ ( paged ) => go( { paged: paged > 1 ? paged : '' } ) }
		/>
	);
	const bulkMenu = data.items.length > 0 && (
		<BulkActions
			disabled={ busy || jobRunning }
			onApply={ bulk }
			actions={ [
				{
					value: 'undo',
					label: __( 'Undo', 'variation-converter-for-woocommerce' ),
				},
				{
					value: 'trash_originals',
					label: __(
						'Move originals to Trash',
						'variation-converter-for-woocommerce'
					),
				},
			] }
		/>
	);
	const kinds = {
		combined: {
			label: __( 'Combined', 'variation-converter-for-woocommerce' ),
			badge: 'ready',
		},
		split: {
			label: __( 'Split', 'variation-converter-for-woocommerce' ),
			badge: 'neutral',
		},
		taken: {
			label: __( 'Made simple', 'variation-converter-for-woocommerce' ),
			badge: 'info',
		},
	};
	const pageIds = data.items.map( ( item ) => item.id );
	const pageAllChecked =
		pageIds.length > 0 &&
		pageIds.every( ( id ) => selected.includes( id ) );
	const total = data.total.toLocaleString();
	return (
		<>
			{ search }
			<Views
				current={ filters.view || '' }
				onChange={ ( view ) =>
					navigate( { tab: 'history', view, s: filters.s || '' } )
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
					...Object.keys( kinds ).map( ( key ) => ( {
						key,
						label: kinds[ key ].label,
						count: data.counts[ key ],
					} ) ),
				] }
			/>
			<Toolbar end={ pagination }>{ bulkMenu }</Toolbar>
			{ message && (
				<Notice
					className="varconv-notice"
					status="warning"
					onRemove={ () => setMessage( '' ) }
				>
					{ message }
				</Notice>
			) }
			{ data.total > data.items.length &&
				( pageAllChecked || allMatching ) && (
					<SelectAllBanner
						all={ allMatching }
						pageText={ sprintf(
							/* translators: %s: number of conversions on this page. */
							__(
								'All %s conversions on this page are selected.',
								'variation-converter-for-woocommerce'
							),
							pageIds.length
						) }
						selectLabel={
							filters.s || filters.view
								? sprintf(
										/* translators: %s: number of conversions. */
										__(
											'Select all %s matching conversions',
											'variation-converter-for-woocommerce'
										),
										total
									)
								: sprintf(
										/* translators: %s: number of conversions. */
										__(
											'Select all %s conversions',
											'variation-converter-for-woocommerce'
										),
										total
									)
						}
						allText={ sprintf(
							/* translators: %s: number of conversions. */
							__(
								'All %s conversions in this list are selected, on every page.',
								'variation-converter-for-woocommerce'
							),
							total
						) }
						clearLabel={ __(
							'Clear selection',
							'variation-converter-for-woocommerce'
						) }
						onSelectAll={ () => setAllMatching( true ) }
						onClear={ () => {
							setSelected( [] );
							setAllMatching( false );
						} }
					/>
				) }
			<ListTable
				className="varconv-history"
				loading={ loading }
				items={ data.items }
				empty={ data.empty }
				getLabel={ ( item ) => item.name }
				selected={ selected }
				onSelect={ ( ids ) => {
					setSelected( ids );
					setAllMatching( false );
				} }
				rowActions={ ( item ) => [
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
					...( item.canUndo
						? [
								{
									key: 'undo',
									label: __(
										'Undo',
										'variation-converter-for-woocommerce'
									),
									destructive: true,
									onClick: () => undoOne( item ),
								},
							]
						: [] ),
					...( item.canTrash
						? [
								{
									key: 'trash',
									label: __(
										'Trash originals',
										'variation-converter-for-woocommerce'
									),
									destructive: true,
									onClick: () => trashOne( item ),
								},
							]
						: [] ),
				] }
				columns={ [
					{
						key: 'product',
						label: __(
							'Product',
							'variation-converter-for-woocommerce'
						),
						primary: true,
						render: ( item ) => (
							<ProductName
								strong
								name={ item.name }
								href={ item.editUrl }
								state={ item.state }
							/>
						),
					},
					{
						key: 'change',
						label: __(
							'Change',
							'variation-converter-for-woocommerce'
						),
						width: 120,
						render: ( item ) => (
							<Badge kind={ kinds[ item.kind ].badge }>
								{ kinds[ item.kind ].label }
							</Badge>
						),
					},
					{
						key: 'products',
						label: __(
							'Products',
							'variation-converter-for-woocommerce'
						),
						render: ( item ) => (
							<RawHTML>{ item.productsHtml }</RawHTML>
						),
					},
					{
						key: 'date',
						label: __(
							'Date',
							'variation-converter-for-woocommerce'
						),
						width: 200,
						render: ( item ) => (
							<span className="varconv-muted">{ item.date }</span>
						),
					},
				] }
			/>
			<Toolbar end={ pagination }>{ bulkMenu }</Toolbar>
			{ dialog }
		</>
	);
}
