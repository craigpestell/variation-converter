import Thumb from './Thumb';
import { swatch } from './stories-data';

export default { title: 'Small parts/Thumb', component: Thumb };

export const WithImage = {
	args: {
		src: swatch( '#2a6f6f' ),
		large: swatch( '#2a6f6f' ),
		name: 'Classic Crew Tee',
		size: 32,
	},
	parameters: {
		docs: {
			description: {
				story: 'Hover or focus the image to see the larger one beside it.',
			},
		},
	},
};
export const NoImage = { args: { size: 32 } };
export const Larger = {
	args: { src: swatch( '#b5651d' ), name: 'Canvas Apron', size: 40 },
};
