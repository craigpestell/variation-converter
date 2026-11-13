import { __, sprintf } from '@wordpress/i18n';

/**
 * A product thumbnail that shows a larger image beside it on hover or keyboard focus. An empty box without an image.
 */
export default function Thumb( { src, large, name = '', size = 32 } ) {
	const style = { width: size, height: size };
	if ( ! src ) {
		return <span className="varconv-thumb is-empty" style={ style } />;
	}
	return (
		<span
			className="varconv-thumb"
			style={ style }

			tabIndex={ 0 }
			role="img"
			aria-label={ sprintf(
				/* translators: %s: product name. */
				__( 'Image of %s', 'variation-converter-for-woocommerce' ),
				name
			) }
		>
			<img src={ src } alt="" style={ style } />
			{ large && (
				<span className="varconv-thumb__large" aria-hidden="true">
					<img src={ large } alt="" loading="lazy" />
				</span>
			) }
		</span>
	);
}
