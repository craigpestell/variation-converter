import { Button } from '@wordpress/components';
import PageHeader from './PageHeader';

export default { title: 'Page/PageHeader', component: PageHeader };

export const TitleOnly = {
	args: { title: 'Variation Converter', version: '1.8.0' },
};
export const WithAction = {
	args: {
		...TitleOnly.args,
		action: <Button variant="secondary">Scan again</Button>,
		note: 'Scanned 5 mins ago',
	},
};
