import { CheckboxControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * The rows a change will include, each with a checkbox, inside a review card: a group's products, a product's
 * variations. Unchecked rows are dimmed. With onMove, rows can be dragged by their handle to reorder them, or moved
 * with the up and down buttons the handle shows on keyboard focus.
 *
 * columns: [ { key, label, width?, hideLabel?, render( row ) } ]
 * rows:    [ { id, checked, disabled?, hidden?, name } ] plus whatever the columns draw.
 */
export default function PickTable( {
	columns,
	rows,
	onToggle,
	onToggleAll,
	onMove,
	checkLabel = ( row ) => row.name,
} ) {
	const [ dragging, setDragging ] = useState( null );
	// The row whose handle is held down: only that row can be dragged, so text in the others can still be selected.
	const [ armed, setArmed ] = useState( null );
	const shown = rows.filter( ( row ) => ! row.hidden );
	const usable = shown.filter( ( row ) => ! row.disabled );
	const all = usable.length > 0 && usable.every( ( row ) => row.checked );
	const some = usable.some( ( row ) => row.checked );
	const index = ( id ) => rows.findIndex( ( row ) => row.id === id );
	return (
		<div className="varconv-pick-table-wrap">
			<table className="varconv-pick-table">
				<thead>
					<tr>
						<td className="varconv-pick-table__check">
							<CheckboxControl
								__nextHasNoMarginBottom
								checked={ all }
								indeterminate={ ! all && some }
								onChange={ onToggleAll }
								aria-label={ __(
									'Check all',
									'variation-converter-for-woocommerce'
								) }
							/>
						</td>
						{ onMove && (
							<th className="varconv-pick-table__handle">
								<span className="screen-reader-text">
									{ __(
										'Order',
										'variation-converter-for-woocommerce'
									) }
								</span>
							</th>
						) }
						{ columns.map( ( column ) => (
							<th
								key={ column.key }
								style={ { width: column.width } }
								className={ `varconv-pick-table__col-${ column.key }` }
							>
								{ column.hideLabel ? (
									<span className="screen-reader-text">
										{ column.label }
									</span>
								) : (
									column.label
								) }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ shown.map( ( row ) => (
						<tr
							key={ row.id }
							className={ [
								row.checked ? '' : 'is-off',
								dragging === row.id ? 'is-dragging' : '',
							].join( ' ' ) }
							draggable={ !! onMove && armed === row.id }
							onDragStart={ ( event ) => {
								event.dataTransfer.effectAllowed = 'move';
								event.dataTransfer.setData(
									'text/plain',
									String( row.id )
								);
								setDragging( row.id );
							} }
							onDragOver={ ( event ) => {
								if ( null === dragging ) {
									return;
								}
								event.preventDefault();
								if ( dragging !== row.id ) {
									onMove(
										index( dragging ),
										index( row.id )
									);
								}
							} }
							onDragEnd={ () => {
								setArmed( null );
								setDragging( null );
							} }
						>
							<td className="varconv-pick-table__check">
								<CheckboxControl
									__nextHasNoMarginBottom
									checked={ !! row.checked }
									disabled={ !! row.disabled }
									onChange={ ( on ) =>
										onToggle( row.id, on )
									}
									aria-label={ sprintf(
										/* translators: %s: product name or SKU. */
										__(
											'Include %s',
											'variation-converter-for-woocommerce'
										),
										checkLabel( row )
									) }
								/>
							</td>
							{ onMove && (
								<td className="varconv-pick-table__handle">
									<span
										className="varconv-pick-table__grip"
										aria-hidden="true"
										onMouseDown={ () => setArmed( row.id ) }
										onMouseUp={ () => setArmed( null ) }
									>
										⠿
									</span>
									<button
										type="button"
										className="varconv-pick-table__move"
										onClick={ () =>
											onMove(
												index( row.id ),
												index( row.id ) - 1
											)
										}
										aria-label={ sprintf(
											/* translators: %s: product name or SKU. */
											__(
												'Move %s up',
												'variation-converter-for-woocommerce'
											),
											checkLabel( row )
										) }
									>
										↑
									</button>
									<button
										type="button"
										className="varconv-pick-table__move"
										onClick={ () =>
											onMove(
												index( row.id ),
												index( row.id ) + 1
											)
										}
										aria-label={ sprintf(
											/* translators: %s: product name or SKU. */
											__(
												'Move %s down',
												'variation-converter-for-woocommerce'
											),
											checkLabel( row )
										) }
									>
										↓
									</button>
								</td>
							) }
							{ columns.map( ( column ) => (
								<td
									key={ column.key }
									className={ `varconv-pick-table__col-${ column.key }` }
								>
									{ column.render( row ) }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
