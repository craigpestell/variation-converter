import '@wordpress/components/build-style/style.css';
import '../src/components/style.scss';
import './preview.scss';

export default {
	parameters: {
		layout: 'padded',
		controls: { expanded: true },
		backgrounds: {
			default: 'WordPress admin',
			values: [
				{ name: 'WordPress admin', value: '#f0f0f1' },
				{ name: 'White', value: '#fff' },
			],
		},
		options: {
			storySort: { order: [ 'Introduction', 'Page', 'Lists', 'Cards', 'Small parts' ] },
		},
	},
};
