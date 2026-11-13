import { Button, TextControl } from '@wordpress/components';
import ReviewCard from './ReviewCard';

export default { title: 'Cards/ReviewCard', component: ReviewCard };

const actions = (
	<>
		<Button variant="secondary" __next40pxDefaultSize>
			Cancel
		</Button>
		<Button variant="primary" __next40pxDefaultSize>
			Create variable product
		</Button>
	</>
);

export const UnderARow = {
	args: {
		help: 'Drag to reorder. The first product gives the variable product its image and description.',
		actions,
		children: (
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label="Product name"
				value="Classic Crew Tee"
				onChange={ () => {} }
			/>
		),
	},
};
export const OnItsOwn = {
	args: {
		...UnderARow.args,
		alone: true,
		title: 'Make a variation of Canvas Apron a simple product',
	},
};
