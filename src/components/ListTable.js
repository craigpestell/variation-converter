import { Button, CheckboxControl, Spinner } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * A list as WordPress admin shows them: a header that sorts, a checkbox per row, row actions under the first column,
 * and optionally a panel that opens under a row (a review card).
 *
 * columns:   [ { key, label, width?, sortable?, primary?, render( item ) } ]
 * rowActions( item ): [ { key, label, onClick?, href?, destructive? } ]
 * selected:  the keys of the checked rows; leave out onSelect for a list without checkboxes.
 * expanded:  the keys of the rows whose panel is open; renderExpanded( item ) draws the panel.
 */
export default function ListTable( {
	columns,
	items,
	getKey = ( item ) => item.id,
	getLabel = () => '',
	selected = [],
	onSelect,
	isSelectable = () => true,
	sort = {},
	onSort,
	rowActions,
	expanded = [],
	renderExpanded,
	rowClass,
	empty,
	loading = false,
	className = '',
} ) {
	const selectable = items.filter( isSelectable ).map( getKey );
	const allChecked =
		selectable.length > 0 &&
		selectable.every( ( key ) => selected.includes( key ) );
	const someChecked = selectable.some( ( key ) => selected.includes( key ) );
	const span = columns.length + ( onSelect ? 1 : 0 );
	const toggleAll = ( on ) =>
		onSelect(
			on
				? [ ...new Set( [ ...selected, ...selectable ] ) ]
				: selected.filter( ( key ) => ! selectable.includes( key ) )
		);
	const toggle = ( key, on ) =>
		onSelect(
			on ? [ ...selected, key ] : selected.filter( ( k ) => k !== key )
		);
	const header = (
		<tr>
			{ onSelect && (
				<td className="varconv-table__check">
					<CheckboxControl
						__nextHasNoMarginBottom
						checked={ allChecked }
						indeterminate={ ! allChecked && someChecked }
						onChange={ toggleAll }
						aria-label={ __(
							'Select all on this page',
							'variation-converter-for-woocommerce'
						) }
					/>
				</td>
			) }
			{ columns.map( ( column ) => {
				const sorted = sort.orderby === column.key;
				const next = sorted && 'asc' === sort.order ? 'desc' : 'asc';
				let ariaSort;
				if ( sorted ) {
					ariaSort =
						'asc' === sort.order ? 'ascending' : 'descending';
				}
				return (
					<th
						key={ column.key }
						scope="col"
						style={ { width: column.width } }
						className={ `varconv-table__col-${ column.key }` }
						aria-sort={ ariaSort }
					>
						{ column.sortable && onSort ? (
							<button
								type="button"
								className="varconv-table__sort"
								onClick={ () =>
									onSort( {
										orderby: column.key,
										order: sorted
											? next
											: column.firstOrder || 'asc',
									} )
								}
							>
								{ column.label }
								<span
									className="varconv-table__sort-arrow"
									aria-hidden="true"
								>
									{ sorted &&
										( 'asc' === sort.order ? '▲' : '▼' ) }
								</span>
							</button>
						) : (
							column.label
						) }
					</th>
				);
			} ) }
		</tr>
	);
	return (
		<div
			className={ `varconv-table-wrap ${ loading ? 'is-loading' : '' }` }
		>
			{ loading && (
				<div className="varconv-table__loading">
					<Spinner />
				</div>
			) }
			<table className={ `varconv-table ${ className }` }>
				<thead>{ header }</thead>
				<tbody>
					{ ! items.length && (
						<tr className="varconv-table__empty">
							<td colSpan={ span }>{ loading ? '' : empty }</td>
						</tr>
					) }
					{ items.map( ( item ) => {
						const key = getKey( item );
						const open = expanded.includes( key );
						const actions = rowActions ? rowActions( item ) : [];
						return (
							<Fragment key={ key }>
								<tr
									className={ [
										open ? 'is-open' : '',
										rowClass ? rowClass( item ) : '',
									].join( ' ' ) }
								>
									{ onSelect && (
										<td className="varconv-table__check">
											{ isSelectable( item ) && (
												<CheckboxControl
													__nextHasNoMarginBottom
													checked={ selected.includes(
														key
													) }
													onChange={ ( on ) =>
														toggle( key, on )
													}
													aria-label={ sprintf(
														/* translators: %s: what the row is, such as a product or group name. */
														__(
															'Select %s',
															'variation-converter-for-woocommerce'
														),
														getLabel( item )
													) }
												/>
											) }
										</td>
									) }
									{ columns.map( ( column ) => (
										<td
											key={ column.key }
											data-label={ column.label }
											className={ `varconv-table__col-${
												column.key
											} ${ column.primary ? 'is-primary' : '' }` }
										>
											{ column.render( item ) }
											{ column.primary &&
												actions.length > 0 && (
													<div className="varconv-table__actions">
														{ actions.map(
															( action ) => (
																<Button
																	key={
																		action.key
																	}
																	variant="link"
																	isDestructive={
																		action.destructive
																	}
																	href={
																		action.href
																	}
																	onClick={
																		action.onClick
																	}
																>
																	{
																		action.label
																	}
																</Button>
															)
														) }
													</div>
												) }
										</td>
									) ) }
								</tr>
								{ open && renderExpanded && (
									<tr className="varconv-table__panel">
										<td colSpan={ span }>
											{ renderExpanded( item ) }
										</td>
									</tr>
								) }
							</Fragment>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
