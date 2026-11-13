import Badge, { BADGE_KINDS } from './Badge';

export default {
	title: 'Small parts/Badge',
	component: Badge,
	argTypes: { kind: { control: 'select', options: BADGE_KINDS } },
};

export const Ready = { args: { kind: 'ready', children: 'Ready' } };
export const Warning = {
	args: {
		kind: 'warn',
		children: 'Prices differ',
		title: '$28.00 for 3 products, $30.00 for 1.',
	},
};
export const Info = { args: { kind: 'info', children: 'Combined' } };
export const Neutral = { args: { kind: 'neutral', children: 'Split' } };
export const AllKinds = {
	render: () => (
		<div style={ { display: 'flex', gap: 8 } }>
			{ BADGE_KINDS.map( ( kind ) => (
				<Badge key={ kind } kind={ kind }>
					{ kind }
				</Badge>
			) ) }
		</div>
	),
};
