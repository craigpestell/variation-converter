/**
 * The page's title with the plugin version beside it, and an action with a note after it ("Scan again", "Scanned 5
 * mins ago").
 */
export default function PageHeader( { title, version, action, note } ) {
	return (
		<header className="varconv-header">
			<h1>
				{ title }
				{ version && (
					<span className="varconv-header__version">{ version }</span>
				) }
			</h1>
			{ action }
			{ note && <span className="varconv-header__note">{ note }</span> }
		</header>
	);
}
