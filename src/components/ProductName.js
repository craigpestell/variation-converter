/**
 * A product's name, linked when there is somewhere to go, with its status after it when it is not published:
 * "Classic Tee — Draft".
 */
export default function ProductName( {
	name,
	href,
	state = '',
	strong = false,
} ) {
	const text = strong ? <strong>{ name }</strong> : name;
	return (
		<span className="varconv-product-name">
			{ href ? <a href={ href }>{ text }</a> : text }
			{ state && (
				<span className="varconv-product-name__state">
					{ ' — ' }
					{ state }
				</span>
			) }
		</span>
	);
}
