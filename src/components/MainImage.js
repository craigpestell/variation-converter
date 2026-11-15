import { Button } from '@wordpress/components';
import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The main image a new variable product will get, shown small: the first product's image, or one the admin chose.
 * "Choose image" opens the WordPress media modal, where an image can be picked from the library or uploaded; "Use
 * first product's image" goes back to the default.
 *
 * @param {Object}                                      props
 * @param {Object|null}                                 props.chosen   The chosen image as { id, url }, or null for the default.
 * @param {string}                                      props.fallback The first product's image URL; empty when it has none.
 * @param {(image: ?{id: number, url: string}) => void} props.onChange Called with { id, url }, or null to use the default.
 */
export default function MainImage( { chosen, fallback = '', onChange } ) {
	const frame = useRef( null );
	const media = 'undefined' !== typeof window ? window.wp?.media : null;
	const open = () => {
		if ( ! frame.current ) {
			frame.current = media( {
				title: __(
					'Main image for the variable product',
					'variation-converter-for-woocommerce'
				),
				button: {
					text: __(
						'Use as main image',
						'variation-converter-for-woocommerce'
					),
				},
				library: { type: 'image' },
				multiple: false,
			} );
		}
		// Rebound each time so the latest onChange is the one called.
		frame.current.off( 'select' );
		frame.current.on( 'select', () => {
			const image = frame.current
				.state()
				.get( 'selection' )
				.first()
				.toJSON();
			const sizes = image.sizes || {};
			onChange( {
				id: image.id,
				url: ( sizes.thumbnail || sizes.medium || image ).url,
			} );
		} );
		frame.current.open();
	};
	const src = chosen ? chosen.url : fallback;
	let caption = __( 'No image', 'variation-converter-for-woocommerce' );
	if ( chosen ) {
		caption = __( 'Chosen image', 'variation-converter-for-woocommerce' );
	} else if ( fallback ) {
		caption = __(
			"The first product's image",
			'variation-converter-for-woocommerce'
		);
	}

	return (
		<div className="varconv-main-image">
			<span className="varconv-main-image__preview">
				{ src ? (
					<img src={ src } alt="" />
				) : (
					<span className="varconv-thumb is-empty" />
				) }
			</span>
			<span className="varconv-main-image__text">
				<strong>
					{ __(
						'Main image',
						'variation-converter-for-woocommerce'
					) }
				</strong>
				<span className="varconv-muted">{ caption }</span>
			</span>
			<span className="varconv-main-image__actions">
				<Button
					variant="secondary"
					size="compact"
					onClick={ open }
					disabled={ ! media }
				>
					{ __(
						'Choose image',
						'variation-converter-for-woocommerce'
					) }
				</Button>
				{ chosen && (
					<Button variant="link" onClick={ () => onChange( null ) }>
						{ __(
							"Use first product's image",
							'variation-converter-for-woocommerce'
						) }
					</Button>
				) }
			</span>
		</div>
	);
}
