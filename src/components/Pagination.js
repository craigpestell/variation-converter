import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * The number of items a list holds and, with more than one page, the links between pages.
 */
export default function Pagination( { page = 1, pages = 1, label, onChange } ) {
	const go = ( to ) => () => onChange( to );
	return (
		<div className="varconv-pagination">
			{ label && (
				<span className="varconv-pagination__count">{ label }</span>
			) }
			{ pages > 1 && (
				<span className="varconv-pagination__links">
					<Button
						variant="secondary"
						size="compact"
						disabled={ page <= 1 }
						onClick={ go( 1 ) }
						label={ __(
							'First page',
							'variation-converter-for-woocommerce'
						) }
					>
						«
					</Button>
					<Button
						variant="secondary"
						size="compact"
						disabled={ page <= 1 }
						onClick={ go( page - 1 ) }
						label={ __(
							'Previous page',
							'variation-converter-for-woocommerce'
						) }
					>
						‹
					</Button>
					<span className="varconv-pagination__page">
						{ sprintf(
							/* translators: 1: current page, 2: number of pages. */
							__(
								'%1$d of %2$d',
								'variation-converter-for-woocommerce'
							),
							page,
							pages
						) }
					</span>
					<Button
						variant="secondary"
						size="compact"
						disabled={ page >= pages }
						onClick={ go( page + 1 ) }
						label={ __(
							'Next page',
							'variation-converter-for-woocommerce'
						) }
					>
						›
					</Button>
					<Button
						variant="secondary"
						size="compact"
						disabled={ page >= pages }
						onClick={ go( pages ) }
						label={ __(
							'Last page',
							'variation-converter-for-woocommerce'
						) }
					>
						»
					</Button>
				</span>
			) }
		</div>
	);
}
