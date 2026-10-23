<?php
/**
 * The Combine tab without reloads (assets/admin.js): create, undo and skip a group, remember unchecked products,
 * check whether a parent SKU is taken, and create selected groups a batch at a time. Each mirrors an admin-post
 * handler in admin.php, which the page falls back to without scripts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every handler below calls varconv_ajax_check() before it reads anything else from $_POST, which the nonce sniff
// cannot see through.
// phpcs:disable WordPress.Security.NonceVerification.Missing

/** Stops unless the current user may convert products and the request carries the nonce for $action. */
function varconv_ajax_check( string $action ): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ) ), 403 );
	}
	if ( ! check_ajax_referer( $action, '_wpnonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'variation-converter-for-woocommerce' ) ), 403 );
	}
}

function varconv_ajax_key(): string {
	return isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
}

add_action( 'wp_ajax_varconv_create', 'varconv_ajax_create' );
function varconv_ajax_create(): void {
	$key = varconv_ajax_key();
	varconv_ajax_check( 'varconv_create_' . $key );
	if ( ! empty( $_POST['dismiss'] ) ) {
		varconv_dismiss( $key );
		wp_send_json_success( array( 'dismissed' => true ) );
	}
	$group = varconv_posted_group();
	try {
		$id = varconv_create_posted( $group );
	} catch ( RuntimeException $e ) {
		wp_send_json_error( array( 'message' => varconv_message( $e ) ) );
	}
	// The checked products are in the new product now; what stays unchecked is the rest of the group.
	varconv_set_excluded( $key, array_keys( $group['members'] ), false );
	wp_send_json_success( array(
		'id'      => $id,
		'members' => array_keys( $group['members'] ),
		'html'    => varconv_created_html( $id ),
		'next'    => varconv_group_of( array_diff( array_keys( $group['labels'] ), array_keys( $group['members'] ) ) ),
	) );
}

/**
 * The key of the group, in a new scan, that holds any of $ids: what is left of a group after part of it was created,
 * for the page to open next. Empty when they are in no group.
 *
 * @param int[] $ids
 */
function varconv_group_of( array $ids ): string {
	if ( ! $ids ) {
		return '';
	}
	foreach ( varconv_candidates()['collections'] as $c ) {
		if ( array_intersect( $ids, array_map( static fn( $m ) => (int) $m['id'], $c['members'] ) ) ) {
			return (string) $c['key'];
		}
	}
	return '';
}

add_action( 'wp_ajax_varconv_undo', 'varconv_ajax_undo' );
function varconv_ajax_undo(): void {
	$id = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
	varconv_ajax_check( 'varconv_undo_' . $id );
	try {
		$restored = varconv_undo( $id );
	} catch ( RuntimeException $e ) {
		wp_send_json_error( array( 'message' => varconv_message( $e ) ) );
	}
	wp_send_json_success( array( 'restored' => $restored ) );
}

/** Remembers products unchecked in a group, so the card shows them unchecked next time and bulk runs leave them out. */
add_action( 'wp_ajax_varconv_exclude', 'varconv_ajax_exclude' );
function varconv_ajax_exclude(): void {
	varconv_ajax_check( 'varconv_ajax' );
	$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
	varconv_set_excluded( varconv_ajax_key(), $ids, ! empty( $_POST['on'] ) );
	wp_send_json_success();
}

/** Which product, if any, already uses a SKU, so the card can warn before Create. */
add_action( 'wp_ajax_varconv_sku', 'varconv_ajax_sku' );
function varconv_ajax_sku(): void {
	varconv_ajax_check( 'varconv_ajax' );
	$sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
	$id = '' === $sku ? 0 : (int) wc_get_product_id_by_sku( $sku );
	if ( ! $id ) {
		wp_send_json_success( array( 'message' => '' ) );
	}
	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: 1: SKU, 2: product name. */
			__( '%1$s is already used by "%2$s". Change it or leave it empty.', 'variation-converter-for-woocommerce' ),
			$sku,
			varconv_product_name( $id )
		),
	) );
}

/** One batch of "Create selected groups". */
add_action( 'wp_ajax_varconv_bulk', 'varconv_ajax_bulk' );
function varconv_ajax_bulk(): void {
	varconv_ajax_check( 'varconv_bulk' );
	$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['keys'] ) ) : array();
	try {
		$counts = varconv_bulk_create( array_slice( $keys, 0, 25 ) );
	} catch ( RuntimeException $e ) {
		wp_send_json_error( array( 'message' => varconv_message( $e ) ) );
	}
	wp_send_json_success( $counts );
}
