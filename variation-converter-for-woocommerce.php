<?php
/**
 * Plugin Name:       Variation Converter for WooCommerce
 * Description:       Finds groups of simple WooCommerce products that are really one product in several variants ("Classic Tee Black", "Classic Tee Blue") and, for each group you approve, makes one variable product, or splits a variable product into one simple product per variation. The originals are hidden, not deleted, and Undo brings them back.
 * Version:           0.5.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Craig Pestell
 * WC requires at least: 8.0
 * WC tested up to:     11.2
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       variation-converter-for-woocommerce
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VARCONV_FILE', __FILE__ );
define( 'VARCONV_VERSION', '0.5.1' );

require_once __DIR__ . '/includes/scan.php';
require_once __DIR__ . '/includes/create.php';
require_once __DIR__ . '/includes/split.php';
require_once __DIR__ . '/includes/job.php';
require_once __DIR__ . '/includes/redirect.php';

/** Product title markup as one line of plain text. */
function varconv_plain( string $html ): string {
	$text = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<br\s*\/?>/i', ' ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( (string) preg_replace( '/[\s\x{00a0}]+/u', ' ', $text ) );
}

/** A product name for matching: plain text, without a leading SKU if the title starts with one. */
function varconv_clean_name( string $title, string $sku ): string {
	$name  = varconv_plain( $title );
	$words = explode( ' ', $name, 2 );
	$code  = static fn( string $s ): string => strtoupper( (string) preg_replace( '/[\s\-_.]+/', '', $s ) );
	return 2 === count( $words ) && '' !== $code( $sku ) && $code( $words[0] ) === $code( $sku ) ? $words[1] : $name;
}

// Touches products only, never orders, so it works with High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VARCONV_FILE, true );
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli.php';
}
if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}
