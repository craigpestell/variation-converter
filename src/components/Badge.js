/**
 * A small colored label for a row's state: "Ready", "Prices differ", "Combined".
 */
export const BADGE_KINDS = [ 'neutral', 'ready', 'warn', 'info' ];

export default function Badge( { kind = 'neutral', title, children } ) {
	return (
		<span className={ `varconv-badge is-${ kind }` } title={ title }>
			{ children }
		</span>
	);
}
