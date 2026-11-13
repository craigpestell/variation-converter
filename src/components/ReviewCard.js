/**
 * The frame of a card that reviews one change before it is made: its content, then a foot with a line of help on the
 * left and the actions on the right. "alone" is a card on its own, not under a list's row.
 */
export default function ReviewCard( {
	children,
	help,
	actions,
	alone = false,
	title,
} ) {
	return (
		<div className={ `varconv-card ${ alone ? 'is-alone' : '' }` }>
			{ title && <h2 className="varconv-card__title">{ title }</h2> }
			{ children }
			{ ( help || actions ) && (
				<div className="varconv-card__foot">
					<span className="varconv-card__help">{ help }</span>
					<span className="varconv-card__actions">{ actions }</span>
				</div>
			) }
		</div>
	);
}
