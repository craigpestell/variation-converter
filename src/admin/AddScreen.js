import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { RawHTML, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { HtmlNotice, ListTable, ProductName, SearchPanel } from '../components';
import { post, errorMessage, useLoad } from './api';
import BackLink from './BackLink';
import { useApp } from './context';
import { navigate } from './router';

/**
 * "Add to a combined product" (add=IDs): first choose the combined product, found by name or SKU; then check each
 * product's labels for its attributes and add them as variations.
 */
export default function AddScreen( { query } ) {
	const { version, notify, changed } = useApp();
	const { data, loading, error } = useLoad(
		'/add',
		{
			add: query.add,
			into: query.into || '',
			...( undefined === query.find ? {} : { find: query.find } ),
		},
		version
	);
	const [ labels, setLabels ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( '' );
	const parent = data ? data.parent : null;
	const parentId = parent ? parent.id : 0;
	useEffect( () => {
		const start = {};
		( ( data && data.products ) || [] ).forEach( ( p ) => {
			start[ p.id ] = p.labels;
		} );
		setLabels( start );
		setFailed( '' );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- the guesses are for the chosen product.
	}, [ parentId, query.add ] );

	const back = (
		<BackLink query={ {} }>
			{ __(
				'All suggested groups',
				'variation-converter-for-woocommerce'
			) }
		</BackLink>
	);
	const title = (
		<h2 className="varconv-list-title">
			{ __(
				'Add to a combined product',
				'variation-converter-for-woocommerce'
			) }
		</h2>
	);
	if ( ! data ) {
		return (
			<>
				{ back }
				{ title }
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
	const notices = data.notices.map( ( notice, i ) => (
		<HtmlNotice key={ i } status={ notice.status } html={ notice.html } />
	) );
	const count = data.products.length;
	if ( ! count ) {
		return (
			<>
				{ back }
				{ title }
				{ notices }
				<div className="varconv-empty">{ data.empty }</div>
			</>
		);
	}

	if ( ! parent ) {
		return (
			<>
				{ back }
				{ title }
				{ notices }
				<p className="varconv-lead">
					{ sprintf(
						/* translators: %d: number of products. */
						_n(
							'Choose the combined product to add %d product to. It becomes a variation, and is hidden as Combine hides its products.',
							'Choose the combined product to add these %d products to. Each becomes a variation, and is hidden as Combine hides its products.',
							count,
							'variation-converter-for-woocommerce'
						),
						count
					) }
				</p>
				<p>{ data.products.map( ( p ) => p.title ).join( ', ' ) }</p>
				<SearchPanel
					label={ __(
						'Find the combined product',
						'variation-converter-for-woocommerce'
					) }
					placeholder={ __(
						'Product name, for example Classic Tee',
						'variation-converter-for-woocommerce'
					) }
					value={ data.find }
					onSearch={ ( find ) =>
						navigate( { add: query.add, find } )
					}
					help={ __(
						'Only products Variation Converter combined are listed, since their Undo can give the products back.',
						'variation-converter-for-woocommerce'
					) }
				/>
				<ListTable
					className="varconv-add-targets"
					loading={ loading }
					items={ data.targets }
					empty={ data.targetsEmpty }
					columns={ [
						{
							key: 'product',
							label: __(
								'Combined product',
								'variation-converter-for-woocommerce'
							),
							primary: true,
							render: ( t ) => (
								<ProductName
									name={ t.name }
									href={ t.editUrl }
									state={ t.state }
								/>
							),
						},
						{
							key: 'variations',
							label: __(
								'Variations',
								'variation-converter-for-woocommerce'
							),
							width: 120,
							render: ( t ) => t.variations,
						},
						{
							key: 'attributes',
							label: __(
								'Attributes',
								'variation-converter-for-woocommerce'
							),
							render: ( t ) => t.attributes,
						},
						{
							key: 'choose',
							label: '',
							width: 220,
							render: ( t ) =>
								t.problem ? (
									<span className="varconv-muted">
										{ t.problem }
									</span>
								) : (
									<Button
										variant="secondary"
										size="compact"
										onClick={ () =>
											navigate( {
												add: query.add,
												into: t.id,
											} )
										}
									>
										{ __(
											'Choose',
											'variation-converter-for-woocommerce'
										) }
									</Button>
								),
						},
					] }
				/>
			</>
		);
	}

	const add = () => {
		setBusy( true );
		setFailed( '' );
		post( '/add', {
			into: parent.id,
			labels: data.products.map( ( p ) => ( {
				id: p.id,
				labels: labels[ p.id ] || p.labels,
			} ) ),
		} ).then(
			( answer ) => {
				setBusy( false );
				notify( answer.notice );
				navigate( {} );
				changed();
			},
			( e ) => {
				setBusy( false );
				setFailed( errorMessage( e ) );
			}
		);
	};
	return (
		<>
			{ back }
			{ title }
			{ notices }
			<p className="varconv-lead">
				<RawHTML>{ parent.leadHtml }</RawHTML>
			</p>
			{ failed && (
				<Notice status="error" isDismissible={ false }>
					{ failed }
				</Notice>
			) }
			{ parent.options.map( ( list, i ) => (
				<datalist key={ i } id={ `varconv-options-${ i }` }>
					{ list.map( ( option ) => (
						<option key={ option } value={ option } />
					) ) }
				</datalist>
			) ) }
			<ListTable
				className="varconv-add-table"
				items={ data.products }
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
						width: 140,
						render: ( p ) => (
							<span className="varconv-mono">
								{ p.sku || '—' }
							</span>
						),
					},
					{
						key: 'price',
						label: __(
							'Price',
							'variation-converter-for-woocommerce'
						),
						width: 120,
						render: ( p ) => p.priceText,
					},
					...parent.attributes.map( ( attribute, i ) => ( {
						key: `attribute-${ i }`,
						label: attribute,
						render: ( p ) => (
							<TextControl
								__nextHasNoMarginBottom
								label={ sprintf(
									/* translators: 1: attribute name, 2: product name. */
									__(
										'%1$s for %2$s',
										'variation-converter-for-woocommerce'
									),
									attribute,
									p.title
								) }
								hideLabelFromVision
								list={ `varconv-options-${ i }` }
								value={
									( labels[ p.id ] || p.labels )[ i ] || ''
								}
								onChange={ ( value ) =>
									setLabels( ( all ) => {
										const row = [
											...( all[ p.id ] || p.labels ),
										];
										row[ i ] = value;
										return { ...all, [ p.id ]: row };
									} )
								}
							/>
						),
					} ) ),
				] }
			/>
			<p className="varconv-help">
				{ __(
					'Each product becomes a variation with its own SKU, price, stock and image, and its old page redirects to it. Undo of the combined product in History gives every product back; Make simple product on one variation takes just that one back out.',
					'variation-converter-for-woocommerce'
				) }
			</p>
			<p>
				<Button
					variant="primary"
					__next40pxDefaultSize
					isBusy={ busy }
					disabled={ busy }
					onClick={ add }
				>
					{ _n(
						'Add as a variation',
						'Add as variations',
						count,
						'variation-converter-for-woocommerce'
					) }
				</Button>
			</p>
		</>
	);
}
