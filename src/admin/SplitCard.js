import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	PickTable,
	ProductName,
	ReviewCard,
	StockStatus,
	Thumb,
} from '../components';
import { post, errorMessage, useLoad } from './api';
import { useConfirm } from './confirm';
import { useApp } from './context';
import { navigate, urlFor } from './router';

/**
 * The card for one variable product: its variations, to split into simple products (the variable product is hidden)
 * or to make just the checked ones simple while the rest stays variable. A product Combine made can only have
 * variations made simple, which shows their original simple products again.
 */
export default function SplitCard( { id, onClose, alone = false } ) {
	const { version, notify, changed } = useApp();
	const { data, error } = useLoad( `/split/${ id }`, {}, version );
	const [ rows, setRows ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );
	const [ failed, setFailed ] = useState( '' );
	const [ ask, dialog ] = useConfirm();
	useEffect( () => {
		if ( data ) {
			setRows(
				data.rows.map( ( r ) => ( {
					...r,
					disabled: '' !== r.problem,
					checked: '' === r.problem && ! data.combined,
				} ) )
			);
		}
	}, [ data ] );

	if ( ! data ) {
		return (
			<ReviewCard alone={ alone }>
				{ error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : (
					<Spinner />
				) }
			</ReviewCard>
		);
	}
	const run = ( action ) => {
		setBusy( action );
		setFailed( '' );
		post( `/split/${ id }`, {
			action,
			rows: rows
				.filter( ( r ) => r.checked )
				.map( ( r ) => ( { id: r.id, name: r.name } ) ),
		} ).then(
			( answer ) => {
				setBusy( '' );
				notify( answer.notice );
				if ( answer.removed ) {
					onClose();
				}
				changed();
			},
			( e ) => {
				setBusy( '' );
				setFailed( errorMessage( e ) );
			}
		);
	};
	const undoSplit = async () => {
		const yes = await ask(
			__(
				'Delete the simple products made from this product and show it again?',
				'variation-converter-for-woocommerce'
			),
			__( 'Undo', 'variation-converter-for-woocommerce' )
		);
		if ( ! yes ) {
			return;
		}
		setBusy( 'undo' );
		post( '/history/undo', { ids: [ id ] } ).then(
			( answer ) => {
				setBusy( '' );
				notify( answer.notice );
				changed();
			},
			( e ) => {
				setBusy( '' );
				setFailed( errorMessage( e ) );
			}
		);
	};

	if ( 'ok' !== data.state ) {
		return (
			<ReviewCard
				alone={ alone }
				title={ alone ? data.name : undefined }
				actions={
					'split' === data.state && (
						<Button
							variant="secondary"
							__next40pxDefaultSize
							isBusy={ 'undo' === busy }
							disabled={ !! busy }
							onClick={ undoSplit }
						>
							{ __(
								'Undo',
								'variation-converter-for-woocommerce'
							) }
						</Button>
					)
				}
				help={ data.message }
			>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ failed }
					</Notice>
				) }
				{ dialog }
			</ReviewCard>
		);
	}

	const count = rows.filter( ( r ) => r.checked ).length;
	const columns = [
		{
			key: 'thumb',
			label: __( 'Image', 'variation-converter-for-woocommerce' ),
			hideLabel: true,
			width: 52,
			render: ( r ) => (
				<Thumb
					src={ r.thumb }
					large={ r.large }
					name={ r.name }
					size={ 40 }
				/>
			),
		},
		{
			key: 'name',
			label: data.combined
				? __(
						'Original product',
						'variation-converter-for-woocommerce'
					)
				: __(
						'New product name',
						'variation-converter-for-woocommerce'
					),
			render: ( r ) => {
				let name;
				if ( r.member ) {
					name = (
						<ProductName
							name={ r.name }
							href={ r.member.editUrl }
						/>
					);
				} else if ( r.taken ) {
					name = r.name;
				} else {
					name = (
						<TextControl
							__nextHasNoMarginBottom
							label={ sprintf(
								/* translators: %d: variation ID. */
								__(
									'New product name for variation %d',
									'variation-converter-for-woocommerce'
								),
								r.id
							) }
							hideLabelFromVision
							value={ r.name }
							onChange={ ( value ) =>
								setRows( ( all ) =>
									all.map( ( row ) =>
										row.id === r.id
											? { ...row, name: value }
											: row
									)
								)
							}
						/>
					);
				}
				let under;
				if ( r.taken ) {
					under = (
						<p className="varconv-row-note">
							{ __(
								'Made simple:',
								'variation-converter-for-woocommerce'
							) }{ ' ' }
							<a href={ r.taken.editUrl }>{ r.taken.name }</a>.{ ' ' }
							<a
								href={ urlFor( {
									tab: 'history',
									view: 'taken',
								} ) }
								onClick={ ( event ) => {
									event.preventDefault();
									navigate( {
										tab: 'history',
										view: 'taken',
									} );
								} }
							>
								{ __(
									'Undo in History',
									'variation-converter-for-woocommerce'
								) }
							</a>
						</p>
					);
				} else if ( r.problem ) {
					under = <p className="varconv-row-note">{ r.problem }</p>;
				} else {
					under = (
						<div className="varconv-row-links">
							{ ! r.enabled && (
								<span className="varconv-muted">
									{ __(
										'Disabled.',
										'variation-converter-for-woocommerce'
									) }{ ' ' }
								</span>
							) }
							<a
								href={ urlFor( {
									tab: 'split',
									make_simple: r.id,
									back: 'split',
								} ) }
								aria-label={ sprintf(
									/* translators: %s: product name. */
									__(
										'Make %s a simple product',
										'variation-converter-for-woocommerce'
									),
									r.name
								) }
								onClick={ ( event ) => {
									event.preventDefault();
									navigate( {
										tab: 'split',
										make_simple: r.id,
										back: 'split',
									} );
								} }
							>
								{ __(
									'Make simple product',
									'variation-converter-for-woocommerce'
								) }
							</a>
						</div>
					);
				}
				return (
					<>
						{ name }
						{ under }
					</>
				);
			},
		},
		{
			key: 'sku',
			label: __( 'SKU', 'variation-converter-for-woocommerce' ),
			width: 130,
			render: ( r ) => <span className="varconv-mono">{ r.sku }</span>,
		},
		{
			key: 'price',
			label: __( 'Price', 'variation-converter-for-woocommerce' ),
			width: 90,
			render: ( r ) => r.priceText,
		},
		{
			key: 'stock',
			label: __( 'Stock', 'variation-converter-for-woocommerce' ),
			width: 100,
			render: ( r ) => (
				<StockStatus status={ r.stock }>{ r.stockText }</StockStatus>
			),
		},
	];
	return (
		<ReviewCard
			alone={ alone }
			title={ alone ? data.name : undefined }
			help={
				data.combined
					? __(
							'Checked variations are disabled and their original products shown again. The rest stays variable; with every variation checked, the variable product moves to the Trash.',
							'variation-converter-for-woocommerce'
						)
					: __(
							'Make simple: the checked variations become simple products and are disabled; the rest stays variable, or moves to the Trash when none is left for sale. Split: every checked variation becomes a simple product and the variable product is hidden. New products copy the description, categories and tags.',
							'variation-converter-for-woocommerce'
						)
			}
			actions={
				<>
					<Button
						variant="secondary"
						__next40pxDefaultSize
						disabled={ !! busy }
						onClick={ onClose }
					>
						{ __(
							'Cancel',
							'variation-converter-for-woocommerce'
						) }
					</Button>
					<Button
						variant={ data.combined ? 'primary' : 'secondary' }
						__next40pxDefaultSize
						isBusy={ 'take' === busy }
						disabled={ !! busy }
						onClick={ () => run( 'take' ) }
					>
						{ __(
							'Make checked simple, keep the rest',
							'variation-converter-for-woocommerce'
						) }
					</Button>
					{ ! data.combined && (
						<Button
							variant="primary"
							__next40pxDefaultSize
							isBusy={ 'split' === busy }
							disabled={ !! busy }
							onClick={ () => run( 'split' ) }
						>
							{ sprintf(
								/* translators: %d: number of simple products to make. */
								_n(
									'Split into %d simple product',
									'Split into %d simple products',
									count,
									'variation-converter-for-woocommerce'
								),
								count
							) }
						</Button>
					) }
				</>
			}
		>
			{ failed && (
				<Notice status="error" isDismissible={ false }>
					{ failed }
				</Notice>
			) }
			{ data.combined && (
				<p>
					{ __(
						"Combined by Variation Converter. Make a variation simple to show its original simple product again, with the variation's current price and stock, while the rest stays variable. Undo in History restores every original product.",
						'variation-converter-for-woocommerce'
					) }
				</p>
			) }
			<PickTable
				columns={ columns }
				rows={ rows }
				onToggle={ ( rowId, on ) =>
					setRows( ( all ) =>
						all.map( ( r ) =>
							r.id === rowId ? { ...r, checked: on } : r
						)
					)
				}
				onToggleAll={ ( on ) =>
					setRows( ( all ) =>
						all.map( ( r ) =>
							r.disabled ? r : { ...r, checked: on }
						)
					)
				}
			/>
			{ dialog }
		</ReviewCard>
	);
}
