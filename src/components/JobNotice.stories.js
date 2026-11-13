import JobNotice from './JobNotice';

export default {
	title: 'Page/JobNotice',
	component: JobNotice,
	argTypes: { onStop: { action: 'stop' } },
};

export const Running = {
	args: {
		text: 'Creating variable products: 20 of 140 done.',
		done: 20,
		total: 140,
	},
};
export const Stopping = { args: { ...Running.args, stopping: true } };
