import SearchPanel from './SearchPanel';

export default {
	title: 'Page/SearchPanel',
	component: SearchPanel,
	argTypes: { onSearch: { action: 'search' } },
};

export const Empty = {
	args: {
		label: 'Find the products to combine',
		placeholder: 'Product name or SKU, for example Classic Tee',
		help: 'Tick the groups and products that are one product, then click Combine into one product.',
	},
};
export const WithSearch = { args: { ...Empty.args, value: 'Barista Syrup' } };
