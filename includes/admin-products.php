<?php
/**
 * Products > All Products: "Combine into variable product" for the checked products, opening their card on the
 * Combine tab whatever their SKUs, for groups the scan does not find; and "Add to a combined product", opening
 * admin-add.php's screen for them, with or without SKUs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'bulk_actions-edit-product', 'varconv_bulk_action' );
function varconv_bulk_action( array $actions ): array {
	if ( current_user_can( VARCONV_CAP ) ) {
		$actions['varconv_combine'] = __( 'Combine into variable product', 'variation-converter-for-woocommerce' );
		$actions['varconv_add'] = __( 'Add to a combined product', 'variation-converter-for-woocommerce' );
	}
	return $actions;
}

// WordPress checks the list's bulk-posts nonce before this filter runs.
add_filter( 'handle_bulk_actions-edit-product', 'varconv_handle_bulk_action', 10, 3 );
function varconv_handle_bulk_action( string $redirect, string $action, array $ids ): string {
	if ( ! in_array( $action, array( 'varconv_combine', 'varconv_add' ), true ) || ! current_user_can( VARCONV_CAP ) ) {
		return $redirect;
	}
	if ( 'varconv_add' === $action ) {
		return varconv_add_url( array_slice( array_map( 'absint', $ids ), 0, VARCONV_ADD_MAX ) );
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
	$f = varconv_filters( $_GET );
	varconv_back_link( $f );
	echo '<p class="varconv-lead">' . esc_html__( 'Combine the products you selected into one variable product. Check the name, attribute and labels, then create it.', 'variation-converter-for-woocommerce' ) . '</p>';
	varconv_left_out_notice( array_combine( $found['refused'], array_map( 'varconv_combine_problem', $found['refused'] ) ) ?: array() );
	varconv_made_simple_notice( array_column( $found['products'], 'id' ) );
	if ( count( $found['products'] ) < 2 ) {
		echo '<div class="varconv-empty"><p>' . esc_html__( 'Select at least two products that can be combined.', 'variation-converter-for-woocommerce' ) . '</p></div>';
		return;
	}
	?>
	<datalist id="varconv-attributes">
		<?php foreach ( array( 'Color', 'Size', 'Flavor', 'Scent', 'Volume', 'Style' ) as $option ) : ?>
			<option><?php echo esc_html( $option ); ?></option>
		<?php endforeach; ?>
	</datalist>
	<table class="wp-list-table widefat fixed striped table-view-list varconv-list-table">
		<thead><?php varconv_list_headers( $f, false ); ?></thead>
		<tbody>
			<?php varconv_render_group( varconv_manual( $found['products'] ), array(), $f, 1, true ); ?>
		</tbody>
	</table>
	<?php
	delete_transient( 'varconv_posted_' . get_current_user_id() );
}
