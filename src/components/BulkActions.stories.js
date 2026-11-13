import BulkActions from './BulkActions';

export default {
	title: 'Lists/BulkActions',
	component: BulkActions,
	argTypes: { onApply: { action: 'apply' } },
};

export const Default = {
	args: {
		actions: [
			{ value: 'undo', label: 'Undo' },
			{ value: 'trash_originals', label: 'Move originals to Trash' },
		],
	},
};
export const Disabled = { args: { ...Default.args, disabled: true } };
