/**
 * The views above a list, as WordPress list tables show them: "All (12) | Ready (8) | Needs review (4)".
 */
export default function Views( { views, current = '', onChange, label } ) {
	return (
		<ul className="varconv-views" aria-label={ label }>
			{ views.map( ( view ) => (
				<li key={ view.key }>
					<button
						type="button"
						className={ view.key === current ? 'is-current' : '' }
						aria-current={
							view.key === current ? 'page' : undefined
						}
						title={ view.tip }
						onClick={ () => onChange( view.key ) }
					>
						{ view.label }{ ' ' }
						<span className="varconv-views__count">
							({ view.count ?? 0 })
						</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}
