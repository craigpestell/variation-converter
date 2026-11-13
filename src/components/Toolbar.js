/**
 * The bar above or below a list: its actions and filters on the left, the item count and page links on the right.
 */
export default function Toolbar( { children, end } ) {
	return (
		<div className="varconv-toolbar">
			<div className="varconv-toolbar__start">{ children }</div>
			{ end && <div className="varconv-toolbar__end">{ end }</div> }
		</div>
	);
}
