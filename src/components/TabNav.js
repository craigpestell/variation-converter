/**
 * The page's tabs, each with an optional count: "Combine 12 | Split | History 3". Tabs are links, so they open in a
 * new browser tab like any link; a plain click changes the tab without loading the page.
 */
export default function TabNav( { tabs, current, onChange } ) {
	return (
		<nav className="varconv-tabs">
			{ tabs.map( ( tab ) => (
				<a
					key={ tab.key }
					href={ tab.href }
					className={ tab.key === current ? 'is-current' : '' }
					aria-current={ tab.key === current ? 'page' : undefined }
					onClick={ ( event ) => {
						if (
							event.metaKey ||
							event.ctrlKey ||
							event.shiftKey ||
							event.button
						) {
							return;
						}
						event.preventDefault();
						onChange( tab.key );
					} }
				>
					{ tab.label }
					{ null !== tab.count && undefined !== tab.count && (
						<span className="varconv-tabs__count">
							{ tab.count }
						</span>
					) }
				</a>
			) ) }
		</nav>
	);
}
