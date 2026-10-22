<?php
/**
 * Plugin Name:       Variant Collections
 * Description:       Finds groups of simple WooCommerce products that are really one product in several variants ("Tri-Rings Black", "Tri-Rings Blue") and, for each group you approve, makes one variable product. The originals are hidden, not deleted, and Undo brings them back.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Craig Pestell
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       variant-collections
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/scan.php';
require_once __DIR__ . '/includes/create.php';

/** Product title markup as one line of plain text. */
function vcol_plain( string $html ): string {
	$text = html_entity_decode( strip_tags( (string) preg_replace( '/<br\s*\/?>/i', ' ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( (string) preg_replace( '/[\s\x{00a0}]+/u', ' ', $text ) );
}

/** A product name for matching: plain text, without a leading SKU if the title starts with one. */
function vcol_clean_name( string $title, string $sku ): string {
	$name  = vcol_plain( $title );
	$words = explode( ' ', $name, 2 );
	$code  = static fn( string $s ): string => strtoupper( (string) preg_replace( '/[\s\-_.]+/', '', $s ) );
	return 2 === count( $words ) && '' !== $code( $sku ) && $code( $words[0] ) === $code( $sku ) ? $words[1] : $name;
}

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}
