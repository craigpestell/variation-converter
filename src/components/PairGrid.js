import { __, sprintf } from '@wordpress/i18n';

/**
 * For a product with two attributes: the first attribute's labels down the side, the second's across the top, and in
 * each cell the SKU of the product that becomes that variation, or "Not sold". A pair two products share is marked.
 *
 * rows: [ { first, second, sku } ], one per product.
 */
export default function PairGrid( { rows } ) {
	if ( ! rows.length ) {
		return null;
	}
	const firsts = [ ...new Set( rows.map( ( r ) => r.first ) ) ];
	const seconds = [ ...new Set( rows.map( ( r ) => r.second ) ) ];
	const cell = ( first, second ) =>
		rows
			.filter( ( r ) => r.first === first && r.second === second )
			.map( ( r ) => r.sku );
	return (
		<div className="varconv-pair-grid" aria-live="polite">
			<table>
				<thead>
					<tr>
						<td />
						{ seconds.map( ( second ) => (
							<th key={ second } scope="col">
								{ second }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ firsts.map( ( first ) => (
						<tr key={ first }>
							<th scope="row">{ first }</th>
							{ seconds.map( ( second ) => {
								const skus = cell( first, second );
								if ( ! skus.length ) {
									return (
										<td
											key={ second }
											className="is-missing"
										>
											{ __(
												'Not sold',
												'variation-converter-for-woocommerce'
											) }
										</td>
									);
								}
								return (
									<td
										key={ second }
										className={
											skus.length > 1 ? 'is-twice' : ''
										}
										title={
											skus.length > 1
												? __(
														'Two products have this pair; change a label.',
														'variation-converter-for-woocommerce'
													)
												: undefined
										}
									>
										{ skus.join( ', ' ) }
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
			<p className="varconv-muted">
				{ sprintf(
					/* translators: 1: number of variations, 2: number of possible pairs. */
					__(
						'%1$d variations of %2$d possible pairs.',
						'variation-converter-for-woocommerce'
					),
					rows.length,
					firsts.length * seconds.length
				) }
			</p>
		</div>
	);
}
