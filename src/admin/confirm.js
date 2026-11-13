import { Button, Modal } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Asks before a change that deletes or hides something. Returns [ ask, dialog ]: ask( message, confirmLabel ) resolves
 * to true or false, and `dialog` goes somewhere in what the component draws.
 */
export function useConfirm() {
	const [ pending, setPending ] = useState( null );
	const ask = useCallback(
		( message, confirmLabel ) =>
			new Promise( ( resolve ) =>
				setPending( { message, confirmLabel, resolve } )
			),
		[]
	);
	const answer = ( value ) => () => {
		pending.resolve( value );
		setPending( null );
	};
	const dialog = pending ? (
		<Modal
			className="varconv-confirm"
			title={ pending.confirmLabel }
			size="small"
			onRequestClose={ answer( false ) }
		>
			<p>{ pending.message }</p>
			<div className="varconv-confirm__actions">
				<Button
					variant="tertiary"
					__next40pxDefaultSize
					onClick={ answer( false ) }
				>
					{ __( 'Cancel', 'variation-converter-for-woocommerce' ) }
				</Button>
				<Button
					variant="primary"
					__next40pxDefaultSize
					onClick={ answer( true ) }
				>
					{ pending.confirmLabel }
				</Button>
			</div>
		</Modal>
	) : null;
	return [ ask, dialog ];
}
