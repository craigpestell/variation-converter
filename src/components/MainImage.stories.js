import MainImage from './MainImage';
import { swatch } from './stories-data';

export default {
	title: 'Small parts/MainImage',
	component: MainImage,
	parameters: {
		docs: {
			description: {
				component:
					'The combine card\'s main image for the new variable product. "Choose image" opens the WordPress media modal, which is only on the plugin page, so it is disabled here.',
			},
		},
	},
};

export const FirstProductsImage = {
	args: { chosen: null, fallback: swatch( '#2a6f6f' ) },
};
export const Chosen = {
	args: {
		chosen: { id: 12, url: swatch( '#b5651d' ) },
		fallback: swatch( '#2a6f6f' ),
	},
};
export const NoImage = { args: { chosen: null, fallback: '' } };
