import { Button } from '@wordpress/components';

/**
 * Shown when every row on a page is checked and the list has more pages: offers every row of the list, and says so
 * once they are selected.
 */
export default function SelectAllBanner( {
	all = false,
	pageText,
	selectLabel,
	allText,
	clearLabel,
	onSelectAll,
	onClear,
} ) {
	return (
		<div className="varconv-select-all" aria-live="polite">
			{ all ? allText : pageText }{ ' ' }
			<Button variant="link" onClick={ all ? onClear : onSelectAll }>
				{ all ? clearLabel : selectLabel }
			</Button>
		</div>
	);
}
