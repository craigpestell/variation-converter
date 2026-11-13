import { Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { HtmlNotice, ListTable } from '../components';
import { useLoad } from './api';
import BackLink from './BackLink';
import { filtersOf } from './CombineTab';
import { useApp } from './context';
import GroupCard, { makeDraft, priceRange } from './GroupCard';
import { navigate } from './router';

/**
 * One card on its own: for products picked by hand (combine=IDs, from Products > All Products or the Combine
 * button), or for groups combined into one product with two attributes (grid=keys).
 */
export default function CardScreen( { query } ) {
	const { version, notify, changed } = useApp();
	const filters = filtersOf( query );
	const { data, error } = useLoad(
		'/combine/card',
		{ combine: query.combine || '', grid: query.grid || '' },
		version
	);
	const [ draft, setDraft ] = useState( null );
	const group = data ? data.group : null;
	const key = group ? group.key : '';
	useEffect( () => {
		setDraft( group ? makeDraft( group ) : null );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- a new card, not a new copy of the same one.
	}, [ key ] );

	const back = (
		<BackLink query={ filters }>
			{ filters.s
				? __(
						'Back to search results',
						'variation-converter-for-woocommerce'
					)
				: __(
						'All suggested groups',
						'variation-converter-for-woocommerce'
					) }
		</BackLink>
	);
	if ( ! data ) {
		return (
			<>
				{ back }
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
	const current = draft || ( group && makeDraft( group ) );
	return (
		<>
			{ back }
			<p className="varconv-lead">{ data.lead }</p>
			{ data.notices.map( ( notice, i ) => (
				<HtmlNotice
					key={ i }
					status={ notice.status }
					html={ notice.html }
				/>
			) ) }
			{ data.empty && (
				<div className="varconv-empty">{ data.empty }</div>
			) }
			{ group && (
				<>
					<ListTable
						className="varconv-groups"
						items={ [ group ] }
						getKey={ ( g ) => g.key }
						columns={ [
							{
								key: 'name',
								label: __(
									'Group',
									'variation-converter-for-woocommerce'
								),
								primary: true,
								render: () => <strong>{ current.name }</strong>,
							},
							{
								key: 'size',
								label: __(
									'Products',
									'variation-converter-for-woocommerce'
								),
								width: 110,
								render: () =>
									current.members.filter( ( m ) => m.checked )
										.length,
							},
							{
								key: 'sku',
								label: __(
									'SKU family',
									'variation-converter-for-woocommerce'
								),
								width: 130,
								render: ( g ) => (
									<span className="varconv-mono">
										{ g.sku }
									</span>
								),
							},
							{
								key: 'price',
								label: __(
									'Price',
									'variation-converter-for-woocommerce'
								),
								width: 150,
								render: () => priceRange( current.members ),
							},
							{
								key: 'attr',
								label: __(
									'Attribute',
									'variation-converter-for-woocommerce'
								),
								width: 160,
								render: () =>
									current.two
										? `${ current.groupAttribute } × ${ current.attribute }`
										: current.attribute,
							},
						] }
						expanded={ [ group.key ] }
						renderExpanded={ () => (
							<GroupCard
								group={ group }
								draft={ current }
								onChange={ setDraft }
								onCreated={ ( answer ) => {
									notify( answer.notice );
									// Part of the products: open the group that holds what is left, if the scan has one.
									navigate(
										! answer.whole && answer.next
											? { ...filters, open: answer.next }
											: filters
									);
									changed();
								} }
							/>
						) }
					/>
				</>
			) }
		</>
	);
}
