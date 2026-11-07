<?php
/**
 * Old product pages keep working after a combine: a simple product that is now a variation sends shoppers (and
 * search engines, with a 301) to that variation's own URL, the variable product with its options chosen. Undo removes
 * the link, so the page shows again. A variant made into a simple product (Make simple) sends its old variant URL to
 * that product. A combined product cannot be split (it is undone instead), and products split into simple products are
 * left as they are.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'varconv_redirect_combined' );
function varconv_redirect_combined(): void {
	if ( ! is_singular( 'product' ) ) {
		return;
	}
	$id = (int) get_queried_object_id();
	$url = varconv_combined_url( $id );
	if ( '' === $url && $_GET ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the URL only, changes nothing.
		$url = varconv_made_simple_url( $id, map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	}
	if ( '' !== $url ) {
		wp_safe_redirect( $url, 301 );
		exit;
	}
}

/**
 * Where a variable product's variant URL (?attribute_size=Large) goes once that variant was made into a simple
 * product (take-out.php): the simple product's page. Keys and values match without regard to case or URL encoding,
 * and other query values (utm_ and the like) are ignored. Empty when the URL names no such variant, when the simple
 * product is not published, or when an enabled variation has those values again, so a live variant is never hidden.
 *
 * @param array<string,mixed> $query The request's query values.
 */
function varconv_made_simple_url( int $parent_id, array $query ): string {
	$chosen = array();
	foreach ( $query as $key => $value ) {
		$key = strtolower( rawurldecode( (string) $key ) );
		if ( 0 === strpos( $key, 'attribute_' ) && is_scalar( $value ) ) {
			$chosen[ $key ] = sanitize_title( rawurldecode( (string) $value ) );
		}
	}
	$parent = $chosen ? wc_get_product( $parent_id ) : null;
	if ( ! $parent instanceof WC_Product_Variable ) {
		return '';
	}
	$target = 0;
	foreach ( $parent->get_children() as $child ) {
		$variation = wc_get_product( $child );
		if ( ! $variation instanceof WC_Product_Variation ) {
			continue;
		}
		$attributes = $variation->get_variation_attributes();
		if ( count( $attributes ) !== count( $chosen ) ) {
			continue;
		}
		foreach ( $attributes as $key => $value ) {
			if ( ( $chosen[ strtolower( (string) $key ) ] ?? null ) !== sanitize_title( (string) $value ) ) {
				continue 2;
			}
		}
		if ( 'publish' === $variation->get_status() ) {
			return '';
		}
		$simple = varconv_taken_simple( (int) $child );
		if ( $simple && 'publish' === get_post_status( $simple ) ) {
			$target = $simple;
		}
	}
	if ( ! $target ) {
		return '';
	}
	/**
	 * Filters where a variant URL of a variation made into a simple product redirects. Return an empty string to show
	 * the variable product instead.
	 *
	 * @param string $url       The simple product's URL.
	 * @param int    $simple_id The simple product.
	 * @param int    $parent_id The variable product.
	 */
	return (string) apply_filters( 'varconv_made_simple_redirect_url', (string) get_permalink( $target ), $target, $parent_id );
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
