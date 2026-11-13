import SearchBox from './SearchBox';

export default {
	title: 'Lists/SearchBox',
	component: SearchBox,
	argTypes: { onSearch: { action: 'search' } },
};

export const Empty = { args: { label: 'Search products' } };
export const WithSearch = { args: { label: 'Search history', value: 'apron' } };
