<?php
/**
 * REST routes for "Add to a combined product" (the Combine tab with add=IDs): the products to add, the combined
 * products to choose from, each product's labels for the chosen one, and the add itself (includes/add-to.php).
 * See rest.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function varconv_rest_add_routes(): void {
	varconv_rest_route( '/add', 'GET', 'varconv_rest_add_screen' );
	varconv_rest_route( '/add', 'POST', 'varconv_rest_add' );
}

/**
 * The screen's data. Without a chosen product ("into"): the combined products matching "find", which starts as what
 * the products' names share. With one: its variation attributes, their options, and a guess at each product's labels.
 */
function varconv_rest_add_screen( WP_REST_Request $request ): array {
	$found = varconv_addable( array_slice( varconv_rest_ids( $request->get_param( 'add' ) ), 0, VARCONV_ADD_MAX ) );
	$products = $found['products'];
	$into = absint( $request->get_param( 'into' ) );
	$parent = $into ? wc_get_product( $into ) : null;
	$out = array(
		'notices'  => array_values( array_filter( array(
			varconv_left_out_html( array_combine( $found['refused'], array_map( static fn( $id ) => varconv_add_problem( wc_get_product( $id ) ), $found['refused'] ) ) ?: array() ),
			varconv_made_simple_html( array_column( $products, 'id' ) ),
			varconv_split_from_html( array_column( $products, 'id' ) ),
		) ) ),
		'products' => array(),
		'targets'  => null,
		'parent'   => null,
		'find'     => '',
		'empty'    => $products ? '' : __( 'Select at least one simple product to add.', 'variation-converter-for-woocommerce' ),
	);
	if ( $parent && '' !== varconv_add_target_problem( $parent ) ) {
		array_unshift( $out['notices'], varconv_rest_notice( 'error', esc_html( varconv_add_target_problem( $parent ) ) ) );
		$parent = null;
	}
	if ( ! $products ) {
		return $out;
	}
	$options = $parent instanceof WC_Product_Variable ? varconv_attribute_options( $parent ) : array();
	foreach ( $products as $p ) {
		$out['products'][] = array(
			'id'        => (int) $p['id'],
			'title'     => (string) $p['title'],
			'state'     => varconv_status_text( (int) $p['id'] ),
			'sku'       => (string) $p['sku'],
			'priceText' => '' !== (string) $p['price'] ? varconv_price_text( (float) $p['price'] ) : __( 'No price', 'variation-converter-for-woocommerce' ),
			'editUrl'   => (string) get_edit_post_link( (int) $p['id'], 'url' ),
			'labels'    => $parent instanceof WC_Product_Variable ? varconv_add_guess( (string) $p['name'], $parent->get_name( 'edit' ), $options, (string) $p['sku'] ) : array(),
		);
	}
	if ( $parent instanceof WC_Product_Variable ) {
		$out['parent'] = array_merge( varconv_rest_product( $parent->get_id() ), array(
			'attributes' => array_values( array_map( 'varconv_attribute_label', varconv_variation_attributes( $parent ) ) ),
			'options'    => $options,
			'leadHtml'   => wp_kses_post( sprintf(
				/* translators: 1: edit link, 2: product name, 3: link to choose another product. */
				__( 'Adding to <a href="%1$s">%2$s</a>. <a href="%3$s">Choose another product</a>', 'variation-converter-for-woocommerce' ),
				esc_url( (string) get_edit_post_link( $parent->get_id() ) ),
				esc_html( $parent->get_name( 'edit' ) ),
				esc_url( varconv_add_url( array_column( $products, 'id' ) ) )
			) ),
		) );
		return $out;
	}
	$find = $request->get_param( 'find' );
	$given = is_string( $find );
	if ( ! $given ) {
		// The first word the names share is usually enough to find their product, and short enough to find it anyway.
		$find = (string) ( varconv_words( (string) $products[0]['name'] )[0] ?? '' );
		foreach ( $products as $p ) {
			if ( '' !== $find && false === stripos( (string) $p['name'], $find ) ) {
				$find = '';
			}
		}
	}
	$find = sanitize_text_field( $find );
	$targets = varconv_add_targets( $find );
	if ( ! $targets && '' !== $find && ! $given ) {
		$find = '';
		$targets = varconv_add_targets( '' );
	}
	$out['find'] = $find;
	$out['targets'] = array();
	foreach ( $targets as $id ) {
		$target = wc_get_product( $id );
		if ( ! $target instanceof WC_Product_Variable ) {
			continue;
		}
		$out['targets'][] = array_merge( varconv_rest_product( $id ), array(
			'variations' => count( $target->get_children() ),
			'attributes' => implode( ', ', array_map( 'varconv_attribute_label', varconv_variation_attributes( $target ) ) ),
			'problem'    => varconv_add_target_problem( $target ),
		) );
	}
	$out['targetsEmpty'] = '' !== $find
		/* translators: %s: what was searched for. */
		? sprintf( __( 'No combined product matches "%s".', 'variation-converter-for-woocommerce' ), $find )
		: __( 'There is no combined product yet. Combine some products first, or combine these with a group on the Combine tab.', 'variation-converter-for-woocommerce' );
	return $out;
}

/** Adds the products to the chosen combined product, each as a variation with the labels given. */
function varconv_rest_add( WP_REST_Request $request ): array {
	$into = absint( $request->get_param( 'into' ) );
	$labels = array();
	foreach ( (array) $request->get_param( 'labels' ) as $row ) {
		$id = is_array( $row ) ? absint( $row['id'] ?? 0 ) : 0;
		if ( $id ) {
			$labels[ $id ] = array_map( 'sanitize_text_field', array_map( 'strval', array_values( array_filter( (array) ( $row['labels'] ?? array() ), 'is_scalar' ) ) ) );
		}
	}
	$made = varconv_add_to( $into, $labels );
	return array( 'notice' => varconv_rest_notice( 'success', varconv_added_html( $into, count( $made ) ) ) );
}
