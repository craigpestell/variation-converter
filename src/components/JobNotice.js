import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * A bulk job's progress while it runs on the server ("Creating groups: 20 of 140 done"), with a Stop button.
 */
export default function JobNotice( {
	text,
	done = 0,
	total = 0,
	stopping = false,
	onStop,
} ) {
	const percent =
		total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
	return (
		<Notice
			className="varconv-notice varconv-job"
			status="info"
			isDismissible={ false }
		>
			<div className="varconv-job__row" aria-live="polite">
				<Spinner />
				<span className="varconv-job__text">
					{ stopping
						? __(
								'Stopping…',
								'variation-converter-for-woocommerce'
							)
						: text }
				</span>
				{ ! stopping && onStop && (
					<Button variant="link" isDestructive onClick={ onStop }>
						{ __( 'Stop', 'variation-converter-for-woocommerce' ) }
					</Button>
				) }
			</div>
			<div
				className="varconv-job__bar"
				role="progressbar"
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuenow={ percent }
			>
				<span style={ { width: `${ percent }%` } } />
			</div>
		</Notice>
	);
}
