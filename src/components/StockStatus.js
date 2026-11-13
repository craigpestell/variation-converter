/**
 * A stock status as WooCommerce names and colors it: "In stock", "Out of stock", "On backorder".
 */
export default function StockStatus( { status, children } ) {
	return (
		<span className={ `varconv-stock is-${ status }` }>{ children }</span>
	);
}
