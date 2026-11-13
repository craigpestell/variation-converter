import { Notice } from '@wordpress/components';
import { RawHTML } from '@wordpress/element';

/**
 * A notice whose text the server wrote, links included ("Created Classic Tee (view)"). The server escapes it.
 */
export default function HtmlNotice( {
	status = 'info',
	html,
	onRemove,
	actions,
	children,
} ) {
	return (
		<Notice
			className="varconv-notice"
			status={ status }
			isDismissible={ !! onRemove }
			onRemove={ onRemove }
			actions={ actions }
		>
			{ html ? <RawHTML>{ html }</RawHTML> : children }
		</Notice>
	);
}
