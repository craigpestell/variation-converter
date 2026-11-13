import {
	Button,
	CheckboxControl,
	Notice,
	SearchControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ChipGroup,
	PairGrid,
	PickTable,
	ProductName,
	ReviewCard,
	StockStatus,
	Thumb,
} from '../components';
import { post, errorMessage } from './api';

const ATTRIBUTES = [ 'Color', 'Size', 'Flavor', 'Scent', 'Volume', 'Style' ];

/**
 * What a card starts with: the group as the scan suggested it, for the admin to change.
 */
export function makeDraft( group ) {
	return {
		name: group.name,
		attribute: group.attribute,
		groupAttribute: group.groupAttribute,
		two: group.grid.length > 0,
		// One of two attributes was removed: each product then needs its own label.
		removed: false,
		members: group.members.map( ( m ) => ( { ...m } ) ),
		categories: group.chosenCats,
		band: null,
		filter: '',
	};
}

/**
 * The price range a product made from the checked members shows: "$9.99 – $12.99".
 */
export function priceRange( members ) {
	const priced = members.filter( ( m ) => m.checked && m.price > 0 );
	if ( ! priced.length ) {
		return '';
	}
	const low = priced.reduce( ( a, b ) => ( b.price < a.price ? b : a ) );
	const high = priced.reduce( ( a, b ) => ( b.price > a.price ? b : a ) );
	return low.price === high.price
		? low.priceText
		: `${ low.priceText } – ${ high.priceText }`;
}

/**
 * Labels more than one checked product has, as the admin spelled them first.
 */
function repeatedLabels( members ) {
	const seen = {};
	const twice = [];
	members
		.filter( ( m ) => m.checked )
		.forEach( ( m ) => {
			const label = m.label.trim();
			const key = label.toLowerCase();
			if (
				'' !== label &&
				seen[ key ] &&
				! twice.includes( seen[ key ] )
			) {
				twice.push( seen[ key ] );
			}
			seen[ key ] = seen[ key ] || label;
		} );
	return twice;
}

/**
 * The review card for one group: the product's name and attribute (or two attributes, for groups combined into one
 * product), its categories when the members' differ, the scan's warnings, and the members to check, label and put in
 * order. Create makes the variable product.
 */
export default function GroupCard( {
	group,
	draft,
	onChange,
	onCreated,
	onSkip,
	onCancel,
	alone = false,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const set = ( patch ) => onChange( { ...draft, ...patch } );
	const { members } = draft;
	const needle = draft.filter.trim().toLowerCase();
	const matches = ( m ) =>
		'' === needle ||
		`${ m.sku } ${ m.title }`.toLowerCase().includes( needle );

	// Remembers which products are unchecked, so the card and bulk runs leave them out next time. Products picked by
	// hand are not a scan group, so there is nothing to remember them by.
	const remember = ( before, after ) => {
		if ( group.manual ) {
			return;
		}
		const changed = after.filter(
			( m, i ) => m.checked !== before[ i ].checked
		);
		[ true, false ].forEach( ( on ) => {
			const ids = changed
				.filter( ( m ) => m.checked !== on )
				.map( ( m ) => m.id );
			if ( ids.length ) {
				post( '/combine/exclude', { key: group.key, ids, on } ).catch(
					() => {}
				);
			}
		} );
	};
	const check = ( test, patch = {} ) => {
		const next = members.map( ( m ) => {
			const checked = test( m );
			return undefined === checked ? m : { ...m, checked };
		} );
		remember( members, next );
		set( { members: next, ...patch } );
	};
	const edit = ( id, patch ) =>
		set( {
			members: members.map( ( m ) =>
				m.id === id ? { ...m, ...patch } : m
			),
		} );
	const move = ( from, to ) => {
		if ( to < 0 || to >= members.length || from === to ) {
			return;
		}
		const next = [ ...members ];
		next.splice( to, 0, next.splice( from, 1 )[ 0 ] );
		set( { members: next } );
	};
	const swap = () => ( {
		attribute: draft.groupAttribute,
		groupAttribute: draft.attribute,
		members: members.map( ( m ) => ( {
			...m,
			label: m.group,
			group: m.label,
		} ) ),
	} );
	const removeAttribute = ( which ) => {
		// Removing the second leaves the first: swap them, then drop what is now the first.
		const kept = 'second' === which ? swap() : {};
		set( { ...kept, two: false, removed: true } );
	};

	const twice = draft.removed ? repeatedLabels( members ) : [];
	const create = () => {
		setBusy( true );
		setError( '' );
		post( '/combine/create', {
			key: group.key,
			name: draft.name,
			attribute: draft.attribute,
			groupAttribute: draft.two ? draft.groupAttribute : null,
			categories: group.catsDiffer ? draft.categories : [],
			members: members.map( ( m ) => ( {
				id: m.id,
				label: m.label,
				group: m.group,
				checked: m.checked,
			} ) ),
		} ).then(
			( answer ) => {
				setBusy( false );
				onCreated( answer );
			},
			( e ) => {
				setBusy( false );
				setError( errorMessage( e ) );
			}
		);
	};

	const labelColumn = ( key, attribute ) => ( {
		key,
		label: attribute,
		width: '22%',
		render: ( m ) => (
			<TextControl
				__nextHasNoMarginBottom
				value={ m[ key ] }
				onChange={ ( value ) => edit( m.id, { [ key ]: value } ) }
				label={ sprintf(
					/* translators: 1: attribute name, e.g. Color, 2: product SKU. */
					__(
						'%1$s for SKU %2$s',
						'variation-converter-for-woocommerce'
					),
					attribute,
					m.sku
				) }
				hideLabelFromVision
			/>
		),
	} );
	const columns = [
		{
			key: 'thumb',
			label: __( 'Image', 'variation-converter-for-woocommerce' ),
			hideLabel: true,
			width: 44,
			render: ( m ) => (
				<Thumb src={ m.thumb } large={ m.large } name={ m.title } />
			),
		},
		...( draft.two
			? [ labelColumn( 'group', draft.groupAttribute ) ]
			: [] ),
		labelColumn( 'label', draft.attribute ),
		{
			key: 'sku',
			label: __( 'SKU', 'variation-converter-for-woocommerce' ),
			width: 120,
			render: ( m ) => (
				<a className="varconv-mono" href={ m.editUrl }>
					{ m.sku }
				</a>
			),
		},
		{
			key: 'title',
			label: __( 'Current title', 'variation-converter-for-woocommerce' ),
			render: ( m ) => (
				<span className="varconv-muted">
					<ProductName name={ m.title } state={ m.state } />
				</span>
			),
		},
		...( group.catsDiffer
			? [
					{
						key: 'cats',
						label: __(
							'Category',
							'variation-converter-for-woocommerce'
						),
						render: ( m ) => m.catNames,
					},
				]
			: [] ),
		{
			key: 'price',
			label: __( 'Price', 'variation-converter-for-woocommerce' ),
			width: 90,
			render: ( m ) => m.priceText,
		},
		{
			key: 'stock',
			label: __( 'Stock', 'variation-converter-for-woocommerce' ),
			width: 100,
			render: ( m ) => (
				<StockStatus status={ m.stock }>{ m.stockText }</StockStatus>
			),
		},
	];

	const attributeField = ( key, label, removeLabel, which ) => (
		<div className="varconv-fields__attribute">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ draft[ key ] }
				onChange={ ( value ) => set( { [ key ]: value } ) }
				list="varconv-attributes"
				required
			/>
			{ which && (
				<Button
					size="small"
					icon="no-alt"
					label={ removeLabel }
					onClick={ () => removeAttribute( which ) }
				/>
			) }
		</div>
	);

	return (
		<ReviewCard
			alone={ alone }
			help={ __(
				'Drag to reorder. The first product gives the variable product its image and description.',
				'variation-converter-for-woocommerce'
			) }
			actions={
				<>
					{ onSkip && (
						<Button
							variant="link"
							isDestructive
							disabled={ busy }
							onClick={ onSkip }
						>
							{ __(
								'Skip, not variants',
								'variation-converter-for-woocommerce'
							) }
						</Button>
					) }
					{ onCancel && (
						<Button
							variant="secondary"
							__next40pxDefaultSize
							disabled={ busy }
							onClick={ onCancel }
						>
							{ __(
								'Cancel',
								'variation-converter-for-woocommerce'
							) }
						</Button>
					) }
					<Button
						variant="primary"
						__next40pxDefaultSize
						isBusy={ busy }
						disabled={ busy || twice.length > 0 }
						onClick={ create }
					>
						{ __(
							'Create variable product',
							'variation-converter-for-woocommerce'
						) }
					</Button>
				</>
			}
		>
			<datalist id="varconv-attributes">
				{ ATTRIBUTES.map( ( option ) => (
					<option key={ option } value={ option } />
				) ) }
			</datalist>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ twice.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: variant labels, such as "4oz", "8oz". */
						__(
							'With one attribute, each product needs its own label. Used more than once: %s. Change the labels or uncheck products.',
							'variation-converter-for-woocommerce'
						),
						twice.map( ( label ) => `"${ label }"` ).join( ', ' )
					) }
				</Notice>
			) }
			<div className={ `varconv-fields ${ draft.two ? 'is-two' : '' }` }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					className="varconv-fields__name"
					label={ __(
						'Product name',
						'variation-converter-for-woocommerce'
					) }
					value={ draft.name }
					onChange={ ( name ) => set( { name } ) }
					required
				/>
				{ draft.two ? (
					<>
						{ attributeField(
							'groupAttribute',
							__(
								'First attribute',
								'variation-converter-for-woocommerce'
							),
							__(
								'Remove the first attribute and its labels',
								'variation-converter-for-woocommerce'
							),
							'first'
						) }
						<Button
							className="varconv-fields__swap"
							variant="secondary"
							icon="leftright"
							label={ __(
								'Swap the two attributes and their labels',
								'variation-converter-for-woocommerce'
							) }
							onClick={ () => set( swap() ) }
						/>
						{ attributeField(
							'attribute',
							__(
								'Second attribute',
								'variation-converter-for-woocommerce'
							),
							__(
								'Remove the second attribute and its labels',
								'variation-converter-for-woocommerce'
							),
							'second'
						) }
					</>
				) : (
					attributeField(
						'attribute',
						__( 'Attribute', 'variation-converter-for-woocommerce' )
					)
				) }
			</div>
			{ group.catsDiffer && (
				<fieldset className="varconv-cats">
					<legend>
						{ __(
							'Categories for the variable product',
							'variation-converter-for-woocommerce'
						) }
					</legend>
					{ group.cats.map( ( cat ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ cat.id }
							label={ cat.name }
							checked={ draft.categories.includes( cat.id ) }
							onChange={ ( on ) =>
								set( {
									categories: on
										? [ ...draft.categories, cat.id ]
										: draft.categories.filter(
												( id ) => id !== cat.id
											),
								} )
							}
						/>
					) ) }
				</fieldset>
			) }
			{ group.notes.length > 0 && (
				<Notice
					className="varconv-card__notes"
					status="warning"
					isDismissible={ false }
				>
					{ group.notes.map( ( note ) => (
						<p key={ note.kind }>
							{ note.lead && <strong>{ note.lead } </strong> }
							{ note.text }
						</p>
					) ) }
				</Notice>
			) }
			{ ( group.bands.length > 0 || members.length > 10 ) && (
				<div className="varconv-card__tools">
					{ group.bands.length > 0 && (
						<ChipGroup
							label={ __(
								'Select by SKU:',
								'variation-converter-for-woocommerce'
							) }
							value={ draft.band }
							onChange={ ( prefix ) =>
								check(
									( m ) =>
										'' === prefix ||
										m.sku
											.trim()
											.toUpperCase()
											.startsWith( prefix ),
									{ band: prefix }
								)
							}
							options={ [
								{
									value: '',
									label: __(
										'All',
										'variation-converter-for-woocommerce'
									),
								},
								...group.bands.map( ( band ) => ( {
									value: band.prefix,
									label: `${ band.prefix } (${ band.count })`,
									ariaLabel: sprintf(
										/* translators: 1: SKU start, 2: number of products. */
										__(
											'Check only the %2$d products whose SKU starts with %1$s',
											'variation-converter-for-woocommerce'
										),
										band.prefix,
										band.count
									),
								} ) ),
							] }
						/>
					) }
					{ members.length > 10 && (
						<span className="varconv-card__filter">
							<SearchControl
								__nextHasNoMarginBottom
								size="compact"
								label={ __(
									'Filter products in this group by SKU or title',
									'variation-converter-for-woocommerce'
								) }
								hideLabelFromVision
								placeholder={ __(
									'Filter by SKU or title',
									'variation-converter-for-woocommerce'
								) }
								value={ draft.filter }
								onChange={ ( filter ) => set( { filter } ) }
							/>
							<Button
								variant="secondary"
								size="compact"
								onClick={ () => check( matches ) }
							>
								{ __(
									'Check shown only',
									'variation-converter-for-woocommerce'
								) }
							</Button>
						</span>
					) }
				</div>
			) }
			<PickTable
				columns={ columns }
				rows={ members.map( ( m ) => ( {
					...m,
					hidden: ! matches( m ),
				} ) ) }
				checkLabel={ ( m ) => m.sku }
				onToggle={ ( id, on ) =>
					check( ( m ) => ( m.id === id ? on : undefined ) )
				}
				onToggleAll={ ( on ) =>
					check( ( m ) => ( matches( m ) ? on : undefined ) )
				}
				onMove={ move }
			/>
			{ draft.two && (
				<PairGrid
					rows={ members
						.filter( ( m ) => m.checked )
						.map( ( m ) => ( {
							first: m.group.trim(),
							second: m.label.trim(),
							sku: m.sku.trim().toUpperCase(),
						} ) ) }
				/>
			) }
		</ReviewCard>
	);
}
