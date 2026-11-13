import { urlFor, navigate } from './router';

/**
 * "← All suggested groups": back to a list, without loading the page.
 */
export default function BackLink( { query = {}, href, children } ) {
	return (
		<p className="varconv-back">
			<a
				href={ href || urlFor( query ) }
				onClick={ ( event ) => {
					if ( href ) {
						return;
					}
					event.preventDefault();
					navigate( query );
				} }
			>
				← { children }
			</a>
		</p>
	);
}
