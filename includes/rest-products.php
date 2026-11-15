<?php
/**
 * Products > All Products: the variants a combined product's row shows when it is expanded
 * (includes/admin-product-groups.php, assets/product-groups.js). One request answers for every row being expanded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The most variable products one request answers for: a Products list page holds up to 999 rows. */
const VARCONV_VARIANTS_MAX = 100;

function varconv_rest_products_routes(): void {
	varconv_rest_route( '/products/variants', 'GET', 'varconv_rest_variants' );
}

/** @return array<int,array> Variable product ID => its variant rows, for each of the request's `ids`. */
function varconv_rest_variants( WP_REST_Request $request ): array {
	$out = array();
	foreach ( array_slice( varconv_rest_ids( $request->get_param( 'ids' ) ), 0, VARCONV_VARIANTS_MAX ) as $id ) {
		if ( current_user_can( 'edit_post', $id ) ) {
			$out[ $id ] = varconv_product_variants( $id );
		}
	}
	return $out;
}

/**
 * A variable product's variations, in the order the shop shows them, each with what the Products list shows for a
 * product (thumbnail, SKU, price, stock) and the simple product it came from, or was made into.
 *
 * @return array<int,array>
 */
function varconv_product_variants( int $parent_id ): array {
	$parent = wc_get_product( $parent_id );
	if ( ! $parent instanceof WC_Product_Variable ) {
		return array();
	}
	$originals = varconv_originals( $parent_id );
	$twins     = varconv_twins( $parent_id, array_merge( $originals['hidden'], $originals['trash'] ) );
	$rows      = array();
	foreach ( $parent->get_children() as $id ) {
		$variation = wc_get_product( $id );
		if ( ! $variation instanceof WC_Product_Variation ) {
			continue;
		}
		$label    = varconv_plain( wc_get_formatted_variation( $variation, true, false ) );
		$original = $twins[ $variation->get_id() ] ?? 0;
		$taken    = varconv_taken_simple( $variation->get_id() );
		$rows[]   = array(
			'id'       => $variation->get_id(),
			'label'    => '' !== $label ? $label : (string) $variation->get_sku( 'edit' ),
			'sku'      => (string) $variation->get_sku( 'edit' ),
			'image'    => wp_kses_post( $variation->get_image( 'thumbnail' ) ),
			'price'    => wp_kses_post( $variation->get_price_html() ),
			'stock'    => varconv_variant_stock_html( $variation ),
			'enabled'  => 'publish' === $variation->get_status( 'edit' ),
			'viewUrl'  => (string) $variation->get_permalink(),
			'original' => $original ? varconv_variant_link( $original, 'trash' === get_post_status( $original ) ? 'trash' : 'hidden' ) : null,
			'taken'    => $taken ? varconv_variant_link( $taken, 'taken' ) : null,
		);
	}
	return $rows;
}

/** A simple product a variant row links to: its name, edit link and how it relates (hidden, trash, taken). */
function varconv_variant_link( int $id, string $kind ): array {
	return array(
		'id'      => $id,
		'name'    => (string) get_post_field( 'post_title', $id, 'raw' ),
		'kind'    => $kind,
		'editUrl' => (string) get_edit_post_link( $id, 'url' ),
	);
}

/** The Stock column's markup for a variation, as WooCommerce writes it for a product: status, then the count. */
function varconv_variant_stock_html( WC_Product_Variation $variation ): string {
	$status  = $variation->get_stock_status();
	$options = wc_get_product_stock_status_options();
	$html    = sprintf( '<mark class="%s">%s</mark>', esc_attr( $status ), esc_html( $options[ $status ] ?? $status ) );
	if ( $variation->managing_stock() ) {
		$html .= ' (' . esc_html( (string) wc_stock_amount( $variation->get_stock_quantity() ) ) . ')';
	}
	return $html;
}
