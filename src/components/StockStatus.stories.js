import StockStatus from './StockStatus';

export default { title: 'Small parts/StockStatus', component: StockStatus };

export const InStock = {
	args: { status: 'instock', children: 'In stock (12)' },
};
export const OutOfStock = {
	args: { status: 'outofstock', children: 'Out of stock' },
};
export const OnBackorder = {
	args: { status: 'onbackorder', children: 'On backorder' },
};
