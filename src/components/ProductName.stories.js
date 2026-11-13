import ProductName from './ProductName';

export default { title: 'Small parts/ProductName', component: ProductName };

export const Linked = { args: { name: 'Classic Crew Tee', href: '#' } };
export const NotPublished = {
	args: { name: 'Classic Crew Tee', href: '#', state: 'Draft', strong: true },
};
export const Plain = { args: { name: 'Classic Crew Tee' } };
