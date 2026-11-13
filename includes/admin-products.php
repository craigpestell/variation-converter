<?php
/**
 * Products > All Products: "Combine into variable product" for the checked products, opening their card on the
 * Combine tab whatever their SKUs, for groups the scan does not find; and "Add to a combined product", opening
 * that screen for them, with or without SKUs.
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
