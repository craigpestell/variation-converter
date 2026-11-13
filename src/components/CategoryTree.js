import { CheckboxControl } from '@wordpress/components';

/**
 * Product categories as a checklist, children under their parents, in a box that scrolls.
 *
 * categories: [ { id, name, children: [ ... ] } ]
 */
export default function CategoryTree( { categories, value = [], onChange } ) {
	const branch = ( list ) => (
		<ul>
			{ list.map( ( category ) => (
				<li key={ category.id }>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ category.name }
						checked={ value.includes( category.id ) }
						onChange={ ( on ) =>
							onChange(
								on
									? [ ...value, category.id ]
									: value.filter(
											( id ) => id !== category.id
										)
							)
						}
					/>
					{ category.children?.length > 0 &&
						branch( category.children ) }
				</li>
			) ) }
		</ul>
	);
	return (
		<div className="varconv-category-tree">{ branch( categories ) }</div>
	);
}
