import HtmlNotice from './HtmlNotice';

export default {
	title: 'Page/HtmlNotice',
	component: HtmlNotice,
	argTypes: {
		status: {
			control: 'select',
			options: [ 'success', 'info', 'warning', 'error' ],
		},
	},
};

export const Success = {
	args: {
		status: 'success',
		html: 'Created <a href="#">Classic Crew Tee</a> (<a href="#">view</a>). Its simple products are now hidden.',
		onRemove: () => {},
		actions: [
			{
				label: 'Undo conversion',
				onClick: () => {},
				variant: 'secondary',
			},
		],
	},
};
export const Warning = {
	args: {
		status: 'warning',
		html: 'Left out 2 products: <strong>Canvas Apron</strong> is a variable product.',
	},
};
export const Error = {
	args: {
		status: 'error',
		children: 'A bulk job is running. Undo when it has finished.',
	},
};
