<?php
/**
 * Plugin Name: Demo store: variations in rows
 * Description: Live Preview only (added by the blueprint, not part of Variation Converter). On a variable product page, lists every variation in its own row (picture, options, price, stock, add to cart) instead of dropdowns.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'woocommerce_variable_add_to_cart',
	function () {
		remove_action( 'woocommerce_variable_add_to_cart', 'woocommerce_variable_add_to_cart', 30 );
	},
	1
);
add_action( 'woocommerce_variable_add_to_cart', 'demo_variant_rows', 30 );

function demo_variant_rows() {
	global $product;
	if ( ! $product instanceof WC_Product_Variable ) {
		return;
	}
	$rows = array();
	foreach ( $product->get_children() as $id ) {
		$variation = wc_get_product( $id );
		if ( $variation && $variation->variation_is_visible() ) {
			$rows[] = $variation;
		}
	}
	if ( ! $rows ) {
		return;
	}
	echo '<style>.demo-variants{width:100%;border-collapse:collapse;margin:0 0 1.5em}.demo-variants tr:nth-child(even) td{background:rgba(0,0,0,.04)}.demo-variants td{padding:.6em .4em;border-bottom:1px solid #ddd;vertical-align:middle}.demo-variants img{width:56px;height:56px;object-fit:cover;display:block}.demo-variants form{display:flex;gap:.4em;justify-content:flex-end;margin:0}.demo-variants input.qty{width:3.5em}.demo-variants .out{color:#a00}</style>';
	echo '<table class="demo-variants"><tbody>';
	foreach ( $rows as $variation ) {
		$names = array();
		foreach ( $variation->get_attributes() as $taxonomy => $value ) {
			$term    = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : false;
			$names[] = $term ? $term->name : $value;
		}
		echo '<tr>';
		echo '<td>' . wp_kses_post( $variation->get_image( 'woocommerce_gallery_thumbnail' ) ) . '</td>';
		echo '<td>' . esc_html( implode( ' / ', $names ) ) . '</td>';
		echo '<td>' . wp_kses_post( $variation->get_price_html() ) . '</td>';
		if ( $variation->is_in_stock() && $variation->is_purchasable() ) {
			echo '<td>' . wp_kses_post( wc_get_stock_html( $variation ) ?: esc_html__( 'In stock', 'woocommerce' ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( $product->get_permalink() ) . '">';
			echo '<input type="number" class="qty" name="quantity" value="1" min="1" step="1" aria-label="' . esc_attr__( 'Quantity', 'woocommerce' ) . '">';
			echo '<button type="submit" name="add-to-cart" value="' . esc_attr( $variation->get_id() ) . '" class="button wp-element-button">' . esc_html__( 'Add to cart', 'woocommerce' ) . '</button>';
			echo '</form></td>';
		} else {
			echo '<td class="out">' . esc_html__( 'Out of stock', 'woocommerce' ) . '</td><td></td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}
