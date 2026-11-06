<?php
/**
 * Products > All Products > Quick Edit on a variable product the plugin made. Its variations keep their own stock
 * (they took it from the simple products), so WooCommerce's Quick Edit stock fields, which set a count on the variable
 * product, change nothing the shop sells. This adds a "Variant stock" box with one number per variation, hides
 * WooCommerce's fields while every variation keeps its own stock, and makes the Stock column show the variations'
 * stock instead of the variable product's.
 *
 * Filter `varconv_quick_edit_variant_stock` (bool $applies, WC_Product $product) turns it on for other variable
 * products too, or off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Of the variations' stock rows, what the Stock column and the form need.
 *
 * @param array $rows Each with manage (bool, its own stock), qty (int|null) and status.
 * @return array{own:array,all_own:bool,status:string,total:int}
 */
function varconv_variant_stock_summary( array $rows ): array {
	$own      = array_values( array_filter( $rows, static fn( $r ) => true === $r['manage'] ) );
	$statuses = array_column( $rows, 'status' );
	if ( in_array( 'instock', $statuses, true ) ) {
		$status = 'instock';
	} elseif ( in_array( 'onbackorder', $statuses, true ) ) {
		$status = 'onbackorder';
	} else {
		$status = 'outofstock';
	}
	return array(
		'own'     => $own,
		'all_own' => $rows && count( $own ) === count( $rows ),
		'status'  => $status,
		'total'   => (int) array_sum( array_map( static fn( $r ) => (int) $r['qty'], $own ) ),
	);
}

/**
 * The variant stock counts Quick Edit should write: each posted number that is a number and differs from the one the
 * form showed. A count left alone is not written, so a sale made while the form was open stands.
 *
 * @param array $posted Variation ID => the number in the box.
 * @param array $shown  Variation ID => the number the box started with.
 * @return array<int,float> Variation ID => new count.
 */
function varconv_variant_stock_changes( array $posted, array $shown ): array {
	$changes = array();
	foreach ( $posted as $id => $value ) {
		$id    = (int) $id;
		$value = trim( (string) $value );
		if ( $id <= 0 || '' === $value || ! is_numeric( $value ) || ! isset( $shown[ $id ] ) ) {
			continue;
		}
		$was = trim( (string) $shown[ $id ] );
		if ( is_numeric( $was ) && (float) $was === (float) $value ) {
			continue;
		}
		$changes[ $id ] = (float) $value;
	}
	return $changes;
}

/** Whether the variant stock box and Stock column apply to $product. */
function varconv_quick_stock_applies( $product ): bool {
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
		return false;
	}
	$applies = '' !== (string) $product->get_meta( VARCONV_META );
	return (bool) apply_filters( 'varconv_quick_edit_variant_stock', $applies, $product );
}

/**
 * The variations of $product with their stock, in the order the shop shows them.
 *
 * @return array<int,array{id:int,sku:string,label:string,manage:bool,qty:int|null,status:string}>
 */
function varconv_variant_stock_rows( WC_Product $product ): array {
	$rows = array();
	foreach ( $product->get_children() as $id ) {
		$variation = wc_get_product( $id );
		if ( ! $variation instanceof WC_Product_Variation ) {
			continue;
		}
		$qty    = $variation->get_stock_quantity( 'edit' );
		$rows[] = array(
			'id'     => $variation->get_id(),
			'sku'    => $variation->get_sku( 'edit' ),
			'label'  => varconv_plain( wc_get_formatted_variation( $variation, true, false ) ),
			'manage' => true === $variation->get_manage_stock( 'edit' ),
			'qty'    => null === $qty ? null : wc_stock_amount( $qty ),
			'status' => $variation->get_stock_status(),
		);
	}
	return $rows;
}

// Row data for the form, after WooCommerce's own in the Name column.
add_action( 'manage_product_posts_custom_column', 'varconv_quick_stock_data', 20, 2 );
function varconv_quick_stock_data( string $column, int $post_id ): void {
	if ( 'name' !== $column || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$product = wc_get_product( $post_id );
	if ( ! varconv_quick_stock_applies( $product ) ) {
		return;
	}
	$summary = varconv_variant_stock_summary( varconv_variant_stock_rows( $product ) );
	if ( ! $summary['own'] ) {
		return;
	}
	$variants = array_map(
		static fn( $r ) => array(
			'id'    => $r['id'],
			'label' => '' !== $r['label'] ? $r['label'] : $r['sku'],
			'sku'   => $r['sku'],
			'qty'   => $r['qty'],
		),
		$summary['own']
	);
	printf(
		'<div class="hidden" id="varconv_stock_%d" data-variants="%s" data-all-own="%s"></div>',
		absint( $post_id ),
		esc_attr( (string) wp_json_encode( $variants ) ),
		$summary['all_own'] ? '1' : '0'
	);
}

// The empty box, filled by assets/quick-edit.js for the product being edited.
add_action( 'woocommerce_product_quick_edit_end', 'varconv_quick_stock_box' );
function varconv_quick_stock_box(): void {
	if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
		return;
	}
	?>
	<div class="inline-edit-group varconv-variant-stock hidden">
		<span class="title"><?php esc_html_e( 'Variant stock', 'variation-converter-for-woocommerce' ); ?></span>
		<p class="description"><?php esc_html_e( 'Each variant keeps its own stock. Change a number to set that variant\'s stock.', 'variation-converter-for-woocommerce' ); ?></p>
		<div class="varconv-variant-stock-list"></div>
	</div>
	<?php
}

add_action( 'admin_enqueue_scripts', 'varconv_quick_stock_assets' );
function varconv_quick_stock_assets( string $hook ): void {
	$screen = get_current_screen();
	if ( 'edit.php' !== $hook || ! $screen || 'edit-product' !== $screen->id || 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
		return;
	}
	$url = plugin_dir_url( VARCONV_FILE ) . 'assets/';
	wp_enqueue_style( 'varconv-quick-edit', $url . 'quick-edit.css', array(), VARCONV_VERSION );
	wp_enqueue_script( 'varconv-quick-edit', $url . 'quick-edit.js', array( 'jquery', 'inline-edit-post', 'woocommerce_quick-edit' ), VARCONV_VERSION, true );
	wp_localize_script( 'varconv-quick-edit', 'varconvQuickEdit', array(
		/* translators: %s: the variant's SKU. */
		'sku' => __( 'SKU %s', 'variation-converter-for-woocommerce' ),
	) );
}

// Runs after WooCommerce has checked the Quick Edit nonce and the edit_post permission, and saved the product.
add_action( 'woocommerce_product_quick_edit_save', 'varconv_quick_stock_save' );
function varconv_quick_stock_save( $product ): void {
	$nonce = isset( $_POST['woocommerce_quick_edit_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_quick_edit_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'woocommerce_quick_edit_nonce' ) || ! isset( $_POST['varconv_stock'], $_POST['varconv_stock_was'] ) ) {
		return;
	}
	if ( ! varconv_quick_stock_applies( $product ) || ! current_user_can( 'edit_post', $product->get_id() ) ) {
		return;
	}
	$posted  = map_deep( wp_unslash( (array) $_POST['varconv_stock'] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by map_deep.
	$shown   = map_deep( wp_unslash( (array) $_POST['varconv_stock_was'] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by map_deep.
	$changed = false;
	foreach ( varconv_variant_stock_changes( $posted, $shown ) as $id => $qty ) {
		$variation = wc_get_product( $id );
		if ( ! $variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $product->get_id() || true !== $variation->get_manage_stock( 'edit' ) ) {
			continue;
		}
		wc_update_product_stock( $variation, wc_stock_amount( $qty ), 'set' );
		$changed = true;
	}
	if ( $changed ) {
		WC_Product_Variable::sync( $product->get_id() );
	}
}

// The Stock column: the variations' status and total stock, each variant's count on hover, instead of the variable
// product's own count, which nothing sells from.
add_filter( 'woocommerce_admin_stock_html', 'varconv_quick_stock_column', 10, 2 );
function varconv_quick_stock_column( $html, $product ) {
	if ( ! varconv_quick_stock_applies( $product ) ) {
		return $html;
	}
	$summary = varconv_variant_stock_summary( varconv_variant_stock_rows( $product ) );
	if ( ! $summary['all_own'] ) {
		return $html;
	}
	$labels = array(
		'instock'     => __( 'In stock', 'variation-converter-for-woocommerce' ),
		'onbackorder' => __( 'On backorder', 'variation-converter-for-woocommerce' ),
		'outofstock'  => __( 'Out of stock', 'variation-converter-for-woocommerce' ),
	);
	$each = array_map(
		static fn( $r ) => ( '' !== $r['label'] ? $r['label'] : $r['sku'] ) . ': ' . (int) $r['qty'],
		$summary['own']
	);
	return sprintf(
		'<mark class="%1$s" title="%2$s">%3$s</mark> (%4$d)',
		esc_attr( $summary['status'] ),
		esc_attr( implode( ', ', $each ) ),
		esc_html( $labels[ $summary['status'] ] ),
		$summary['total']
	);
}
