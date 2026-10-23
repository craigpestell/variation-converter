<?php
/**
 * Old product pages keep working after a combine: a simple product that is now a variation sends shoppers (and
 * search engines, with a 301) to the variable product, with its option already chosen. Undo removes the link, so the
 * page shows again. Products split into simple products are left as they are.
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
 * Where a combined simple product's page now lives: its variable product, with the variation that took its SKU
 * chosen. Empty when the product is not in a published variable product made here.
 */
function varconv_combined_url( int $id ): string {
	$parent_id = (int) get_post_meta( $id, VARCONV_PARENT_META, true );
	$parent = $parent_id ? wc_get_product( $parent_id ) : null;
	if ( ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== $parent->get_status() ) {
		return '';
	}
	$url = (string) get_permalink( $parent_id );
	$sku = (string) get_post_meta( $id, '_sku', true );
	if ( '' !== $sku ) {
		foreach ( $parent->get_children() as $child ) {
			$variation = wc_get_product( $child );
			if ( $variation && $variation->get_sku( 'edit' ) === $sku ) {
				foreach ( $variation->get_attributes() as $key => $value ) {
					$url = add_query_arg( 'attribute_' . $key, rawurlencode( (string) $value ), $url );
				}
				break;
			}
		}
	}
	/**
	 * Filters where a combined simple product's page redirects. Return an empty string to show the old page instead.
	 *
	 * @param string $url       The variable product's URL, with the option chosen.
	 * @param int    $id        The simple product.
	 * @param int    $parent_id The variable product.
	 */
	return (string) apply_filters( 'varconv_redirect_url', $url, $id, $parent_id );
}
