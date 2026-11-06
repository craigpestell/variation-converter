<?php
/**
 * Old product pages keep working after a combine: a simple product that is now a variation sends shoppers (and
 * search engines, with a 301) to that variation's own URL, the variable product with its options chosen. Undo removes
 * the link, so the page shows again. A combined product cannot be split (it is undone instead), and products split
 * into simple products are left as they are.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'varconv_redirect_combined' );
function varconv_redirect_combined(): void {
	if ( ! is_singular( 'product' ) ) {
		return;
	}
	$url = varconv_combined_url( (int) get_queried_object_id() );
	if ( '' !== $url ) {
		wp_safe_redirect( $url, 301 );
		exit;
	}
}

/**
 * Where a combined simple product's page now lives: the variant URL of the variation it became, which is the variable
 * product's permalink plus one rawurlencoded `attribute_*` value per attribute, in the order
 * WC_Product_Variation::get_variation_attributes() gives them (spaces become %20). When that variation is not enabled,
 * has an "Any" attribute, or cannot be found, the variable product's own URL. Empty when the product is not in a
 * published variable product made here, so Undo, which removes the link, stops the redirect.
 */
function varconv_combined_url( int $id ): string {
	$parent_id = (int) get_post_meta( $id, VARCONV_PARENT_META, true );
	$parent = $parent_id ? wc_get_product( $parent_id ) : null;
	if ( ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== $parent->get_status() ) {
		return '';
	}
	$url = (string) get_permalink( $parent_id );
	$variation_id = (int) array_search( $id, varconv_twins( $parent_id, array( $id ) ), true );
	$variant = $variation_id ? varconv_variant_url( $variation_id ) : '';
	/**
	 * Filters where a combined simple product's page redirects. Return an empty string to show the old page instead.
	 *
	 * @param string $url       The variant URL, or the variable product's URL when the variation has none.
	 * @param int    $id        The simple product.
	 * @param int    $parent_id The variable product.
	 */
	return (string) apply_filters( 'varconv_redirect_url', '' !== $variant ? $variant : $url, $id, $parent_id );
}

/**
 * A variation's own URL: its variable product's permalink with each attribute chosen, for example
 * /product/candle/?attribute_scent=Sea%20Salt (custom attributes use the value as saved, taxonomy attributes the term
 * slug). Empty for a variation that is not enabled or has an "Any" attribute, since no single variant is named.
 */
function varconv_variant_url( int $variation_id ): string {
	$variation = wc_get_product( $variation_id );
	if ( ! $variation instanceof WC_Product_Variation || 'publish' !== $variation->get_status() ) {
		return '';
	}
	$query = array();
	foreach ( $variation->get_variation_attributes() as $key => $value ) {
		if ( '' === (string) $value ) {
			return '';
		}
		$query[ $key ] = rawurlencode( (string) $value );
	}
	return array() === $query ? '' : add_query_arg( $query, (string) get_permalink( $variation->get_parent_id() ) );
}
