<?php
/**
 * Products > All Products: "Combine into variable product" for the checked products, opening their card on the
 * Combine tab whatever their SKUs, for groups the scan does not find.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'bulk_actions-edit-product', 'varconv_bulk_action' );
function varconv_bulk_action( array $actions ): array {
	if ( current_user_can( VARCONV_CAP ) ) {
		$actions['varconv_combine'] = __( 'Combine into variable product', 'variation-converter' );
	}
	return $actions;
}

// WordPress checks the list's bulk-posts nonce before this filter runs.
add_filter( 'handle_bulk_actions-edit-product', 'varconv_handle_bulk_action', 10, 3 );
function varconv_handle_bulk_action( string $redirect, string $action, array $ids ): string {
	if ( 'varconv_combine' !== $action || ! current_user_can( VARCONV_CAP ) ) {
		return $redirect;
	}
	return varconv_url( array( 'combine' => implode( ',', array_map( 'absint', $ids ) ) ) );
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; Create checks its own nonce.

/** The product IDs the bulk action passed, if any. @return int[] */
function varconv_combine_ids(): array {
	$list = isset( $_GET['combine'] ) ? sanitize_text_field( wp_unslash( $_GET['combine'] ) ) : '';
	return array_slice( array_values( array_filter( array_map( 'absint', explode( ',', $list ) ) ) ), 0, 200 );
}

/** The card for the products picked in Products > All Products. */
function varconv_render_manual( array $ids ): void {
	$found = varconv_products_by_id( $ids );
	echo '<p><a href="' . esc_url( varconv_url() ) . '">&larr; ' . esc_html__( 'All suggested groups', 'variation-converter' ) . '</a></p>';
	echo '<p class="varconv-intro">' . esc_html__( 'Combine the products you selected into one variable product. Check the name, attribute and labels, then create it.', 'variation-converter' ) . '</p>';
	if ( $found['refused'] ) {
		printf(
			'<div class="notice notice-warning inline"><p>%s %s</p></div>',
			esc_html( sprintf(
				/* translators: %d: number of selected products left out. */
				_n( '%d selected product is left out: only published simple products with a SKU, shown in the catalog and not already combined, can be combined.', '%d selected products are left out: only published simple products with a SKU, shown in the catalog and not already combined, can be combined.', count( $found['refused'] ), 'variation-converter' ),
				count( $found['refused'] )
			) ),
			wp_kses_post( varconv_product_links( $found['refused'] ) )
		);
	}
	if ( count( $found['products'] ) < 2 ) {
		echo '<div class="varconv-empty"><p>' . esc_html__( 'Select at least two products that can be combined.', 'variation-converter' ) . '</p></div>';
		return;
	}
	?>
	<datalist id="varconv-attributes">
		<?php foreach ( array( 'Color', 'Size', 'Flavor', 'Scent', 'Volume', 'Style' ) as $option ) : ?>
			<option><?php echo esc_html( $option ); ?></option>
		<?php endforeach; ?>
	</datalist>
	<div class="varconv-groups">
		<?php varconv_render_group( varconv_manual( $found['products'] ), array(), varconv_filters( array() ), 1, true ); ?>
	</div>
	<?php
	delete_transient( 'varconv_posted_' . get_current_user_id() );
}
