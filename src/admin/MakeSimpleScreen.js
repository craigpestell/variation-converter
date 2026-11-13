import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { CategoryTree, ReviewCard, StockStatus, Thumb } from '../components';
import { post, errorMessage, useLoad } from './api';
import BackLink from './BackLink';
import { useApp } from './context';
import { navigate } from './router';

/**
 * Make one variation a simple product (make_simple=ID): what the simple product will be, its name and categories.
 * Reached from a variation in the product edit screen's Variations panel (back=edit) or from a row of the Split
 * card, and goes back to where it came from.
 */
export default function MakeSimpleScreen( { query } ) {
	const { notify, changed } = useApp();
	const id = parseInt( query.make_simple, 10 ) || 0;
	const back = 'edit' === query.back ? 'edit' : 'split';
	const { data, error } = useLoad( `/variation/${ id }`, { back } );
	const [ name, setName ] = useState( '' );
	const [ cats, setCats ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( '' );
	useEffect( () => {
		if ( data && data.row ) {
			setName( data.row.name );
			setCats( data.chosenCats );
		}
	}, [ data ] );

	const toSplit = ( parent ) =>
		navigate( { tab: 'split', product: parent || '' } );
	const leave = ( parent ) => {
		if ( 'edit' === back && data && data.backUrl ) {
			window.location.assign( data.backUrl );
		} else {
			toSplit( parent );
		}
	};
	const backLink = (
		<BackLink
			href={ 'edit' === back && data ? data.backUrl : undefined }
			query={ { tab: 'split', product: ( data && data.parent ) || '' } }
		>
			{ __( 'Back', 'variation-converter-for-woocommerce' ) }
		</BackLink>
	);
	if ( ! data ) {
		return (
			<>
				{ backLink }
				{ error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : (
					<Spinner />
				) }
			</>
		);
	}
	if ( data.problem ) {
		return (
			<>
				{ backLink }
				<Notice status="warning" isDismissible={ false }>
					{ data.problem }
				</Notice>
			</>
		);
	}
	const submit = () => {
		setBusy( true );
		setFailed( '' );
		post( `/variation/${ id }`, { name, categories: cats, back } ).then(
			( answer ) => {
				if ( answer.url ) {
					// The product edit screen shows the notice itself.
					window.location.assign( answer.url );
					return;
				}
				setBusy( false );
				notify( answer.notice );
				toSplit( answer.removed ? 0 : answer.parent );
				changed();
			},
			( e ) => {
				setBusy( false );
				setFailed( errorMessage( e ) );
			}
		);
	};
	const { row } = data;
	return (
		<>
			{ backLink }
			<ReviewCard
				alone
				title={ data.title }
				actions={
					<>
						<Button
							variant="secondary"
							__next40pxDefaultSize
							disabled={ busy }
							onClick={ () => leave( data.parent ) }
						>
							{ __(
								'Cancel',
								'variation-converter-for-woocommerce'
							) }
						</Button>
						<Button
							variant="primary"
							__next40pxDefaultSize
							isBusy={ busy }
							disabled={ busy || ! name.trim() }
							onClick={ submit }
						>
							{ __(
								'Make simple product',
								'variation-converter-for-woocommerce'
							) }
						</Button>
					</>
				}
			>
				<p>{ data.lead }</p>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ failed }
					</Notice>
				) }
				{ data.notes.map( ( note, i ) => (
					<Notice
						key={ i }
						status={ note.status }
						isDismissible={ false }
					>
						{ note.text }
					</Notice>
				) ) }
				<div className="varconv-pick-table-wrap">
					<table className="varconv-pick-table">
						<thead>
							<tr>
								<th style={ { width: 52 } }>
									<span className="screen-reader-text">
										{ __(
											'Image',
											'variation-converter-for-woocommerce'
										) }
									</span>
								</th>
								<th>
									{ __(
										'Product name',
										'variation-converter-for-woocommerce'
									) }
								</th>
								<th style={ { width: 130 } }>
									{ __(
										'SKU',
										'variation-converter-for-woocommerce'
									) }
								</th>
								<th style={ { width: 90 } }>
									{ __(
										'Price',
										'variation-converter-for-woocommerce'
									) }
								</th>
								<th style={ { width: 130 } }>
									{ __(
										'Stock',
										'variation-converter-for-woocommerce'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>
									<Thumb
										src={ row.thumb }
										large={ row.large }
										name={ row.name }
										size={ 40 }
									/>
								</td>
								<td>
									<TextControl
										__nextHasNoMarginBottom
										label={ __(
											'Product name',
											'variation-converter-for-woocommerce'
										) }
										hideLabelFromVision
										value={ name }
										onChange={ setName }
										required
									/>
								</td>
								<td>
									<span className="varconv-mono">
										{ row.sku }
									</span>
								</td>
								<td>{ row.priceText }</td>
								<td>
									<StockStatus status={ row.stock }>
										{ row.stockText }
									</StockStatus>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<fieldset className="varconv-take-cats">
					<legend>
						{ __(
							'Categories',
							'variation-converter-for-woocommerce'
						) }
					</legend>
					<p className="varconv-muted">{ data.catsHelp }</p>
					<CategoryTree
						categories={ data.categories }
						value={ cats }
						onChange={ setCats }
					/>
				</fieldset>
			</ReviewCard>
		</>
	);
}
