import { useState } from '@wordpress/element';
import SelectAllBanner from './SelectAllBanner';

export default { title: 'Lists/SelectAllBanner', component: SelectAllBanner };

export const Default = {
	render: () => {
		const [ all, setAll ] = useState( false );
		return (
			<SelectAllBanner
				all={ all }
				pageText="All 20 conversions on this page are selected."
				selectLabel="Select all 134 conversions"
				allText="All 134 conversions are selected."
				clearLabel="Clear selection"
				onSelectAll={ () => setAll( true ) }
				onClear={ () => setAll( false ) }
			/>
		);
	},
};
