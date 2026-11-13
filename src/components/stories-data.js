/* Sample data shared by the stories: a small catalog as the plugin's REST routes describe it. */
export const groups = [
	{
		key: 'tee',
		name: 'Classic Crew Tee',
		products: 4,
		sku: 'TEE100',
		price: '$24.00',
		attribute: 'Color',
		status: 'ready',
	},
	{
		key: 'mug',
		name: 'Enamel Camp Mug 12oz',
		products: 3,
		sku: 'MUG12',
		price: '$16.00',
		attribute: 'Color',
		status: 'ready',
	},
	{
		key: 'vneck',
		name: 'Organic V-Neck Tee',
		products: 4,
		sku: 'VN220',
		price: '$28.00 – $30.00',
		attribute: 'Size',
		status: 'warn',
	},
];

export const members = [
	{
		id: 1,
		checked: true,
		name: 'Organic V-Neck Tee (Small)',
		label: 'Small',
		sku: 'VN220-S',
		price: '$28.00',
	},
	{
		id: 2,
		checked: true,
		name: 'Organic V-Neck Tee (Medium)',
		label: 'Medium',
		sku: 'VN220-M',
		price: '$28.00',
	},
	{
		id: 3,
		checked: true,
		name: 'Organic V-Neck Tee (Large)',
		label: 'Large',
		sku: 'VN220-L',
		price: '$28.00',
	},
	{
		id: 4,
		checked: false,
		name: 'Organic V-Neck Tee (XL)',
		label: 'XL',
		sku: 'VN220-XL',
		price: '$30.00',
	},
];

export const categories = [
	{
		id: 1,
		name: 'Apparel',
		children: [
			{ id: 2, name: 'Tees', children: [] },
			{ id: 3, name: 'Aprons', children: [] },
		],
	},
	{ id: 4, name: 'Coffee', children: [] },
	{ id: 5, name: 'Sale', children: [] },
];

/**
 * A square of one color as an image, so the stories need no files.
 */
export const swatch = ( color ) =>
	'data:image/svg+xml,' +
	encodeURIComponent(
		`<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="${ color }"/></svg>`
	);
