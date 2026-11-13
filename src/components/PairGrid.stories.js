import PairGrid from './PairGrid';

export default { title: 'Cards/PairGrid', component: PairGrid };

const rows = [
	{ first: 'Caramel', second: '250ml', sku: 'SYR-CA-02' },
	{ first: 'Caramel', second: '750ml', sku: 'SYR-CA-07' },
	{ first: 'Vanilla', second: '250ml', sku: 'SYR-VA-02' },
	{ first: 'Vanilla', second: '750ml', sku: 'SYR-VA-07' },
];

export const EveryPairSold = { args: { rows } };
export const PairsNotSold = {
	args: {
		rows: [
			...rows,
			{ first: 'Hazelnut', second: '750ml', sku: 'HZL750' },
		],
	},
};
export const PairUsedTwice = {
	args: {
		rows: [
			...rows,
			{ first: 'Vanilla', second: '750ml', sku: 'SYR-VA-07B' },
		],
	},
};
