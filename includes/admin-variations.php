<?php
/**
 * Make simple, one variation at a time, from the product edit screen: a "Make simple product" link on each variation
 * in the Variations panel opens the app's confirm screen on the Split tab (name and categories), which comes back
 * here with a notice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// In the variation's header row, beside WooCommerce's Remove.
add_action( 'woocommerce_variation_header', 'varconv_variation_header', 10, 2 );
function varconv_variation_header( $variation, $loop ): void {
	unset( $loop );
	if ( ! current_user_can( VARCONV_CAP ) || ! $variation instanceof WP_Post ) {
		return;
	}
	$id = (int) $variation->ID;
	$taken = varconv_taken_simple( $id );
	if ( $taken ) {
		printf(
			'<span class="varconv-made-simple">%s <a href="%s">%s</a></span>',
			esc_html__( 'Made simple:', 'variation-converter-for-woocommerce' ),
			esc_url( (string) get_edit_post_link( $taken ) ),
			esc_html( get_post_field( 'post_title', $taken, 'raw' ) )
		);
		return;
	}
	if ( '' !== (string) get_post_meta( (int) $variation->post_parent, VARCONV_SPLIT_META, true ) ) {
		return;
	}
	printf(
		'<a href="%s" class="varconv-make-simple">%s</a>',
		esc_url( varconv_take_one_url( $id, 'edit' ) ),
		esc_html__( 'Make simple product', 'variation-converter-for-woocommerce' )
	);
}

add_action( 'admin_enqueue_scripts', 'varconv_variations_assets' );
function varconv_variations_assets( string $hook ): void {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() || ! current_user_can( VARCONV_CAP ) ) {
		return;
	}
	$url = plugin_dir_url( VARCONV_FILE ) . 'assets/';
	wp_enqueue_style( 'varconv-variations', $url . 'variations.css', array(), VARCONV_VERSION );
	wp_enqueue_script( 'varconv-variations', $url . 'variations.js', array( 'jquery' ), VARCONV_VERSION, true );
}

// The product edit screen's notice after Make simple. Display only; the change itself checked its nonce.
add_action( 'admin_notices', 'varconv_edit_screen_notice' );
function varconv_edit_screen_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! $screen || 'product' !== $screen->id || ! current_user_can( VARCONV_CAP ) || ! isset( $_GET['varconv_done'] ) || 'taken' !== $_GET['varconv_done'] ) {
		return;
	}
	$count = isset( $_GET['varconv_count'] ) ? absint( wp_unslash( $_GET['varconv_count'] ) ) : 0;
	$made = isset( $_GET['varconv_made'] ) ? absint( wp_unslash( $_GET['varconv_made'] ) ) : 0;
	$removed = ! empty( $_GET['varconv_removed'] ) && isset( $_GET['varconv_product'] ) ? absint( wp_unslash( $_GET['varconv_product'] ) ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', wp_kses_post( varconv_taken_notice_html( $count, $made, $removed ) ) );
}
