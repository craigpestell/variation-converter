<?php
/**
 * A "1" count bubble on Products > Variation Converter (and on Products, so it shows while the menu is closed) after a
 * first install, so a new store owner can find the page. It goes away the first time someone who can use the page
 * opens it. Upgrades and reinstalls on a store that already used the plugin get no bubble. No notices, nothing else.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * array( 'version' => first version that ran here, 'badge' => whether the bubble shows ). One autoloaded option, so
 * checking it costs no query on admin pages.
 */
const VARCONV_INSTALL_OPTION = 'varconv_install';

/** On activation: a bubble only if the plugin never ran here before. */
function varconv_badge_activate(): void {
	if ( false === get_option( VARCONV_INSTALL_OPTION ) ) {
		add_option( VARCONV_INSTALL_OPTION, array( 'version' => VARCONV_VERSION, 'badge' => ! varconv_used_before() ) );
	}
}

/**
 * Whether an earlier copy (before VARCONV_INSTALL_OPTION existed) left anything behind: its settings, or products it
 * combined or split.
 */
function varconv_used_before(): bool {
	global $wpdb;
	if ( false !== get_option( 'varconv_dismissed' ) || false !== get_option( 'varconv_excluded' ) ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- runs once, on activation.
	return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key IN ( '_varconv_collection', '_varconv_split', '_varconv_split_from' ) LIMIT 1" );
}

/** Upgrades skip activation: mark them installed, without a bubble, so a later deactivate and reactivate shows none. */
add_action( 'admin_init', 'varconv_badge_mark_upgrade' );
function varconv_badge_mark_upgrade(): void {
	if ( false === get_option( VARCONV_INSTALL_OPTION ) ) {
		add_option( VARCONV_INSTALL_OPTION, array( 'version' => VARCONV_VERSION, 'badge' => false ) );
	}
}

/** The count bubble WordPress uses on its own menu items (Plugins, Comments). */
function varconv_badge_html(): string {
	return ' <span class="update-plugins count-1"><span class="plugin-count" aria-hidden="true">1</span><span class="screen-reader-text">'
		. esc_html__( 'New', 'variation-converter-for-woocommerce' ) . '</span></span>';
}

/** Whether to show the bubble: still unseen, the user can open the page, and this isn't the page itself. */
function varconv_badge_showing(): bool {
	$install = get_option( VARCONV_INSTALL_OPTION );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared, nothing is changed.
	$here = isset( $_GET['page'] ) && VARCONV_PAGE === $_GET['page'];
	return is_array( $install ) && ! empty( $install['badge'] ) && ! $here && current_user_can( VARCONV_CAP );
}

/** Opening the page clears the bubble for everyone. Runs on the page's load hook, which checks the capability. */
function varconv_badge_seen(): void {
	$install = get_option( VARCONV_INSTALL_OPTION );
	if ( is_array( $install ) && ! empty( $install['badge'] ) ) {
		$install['badge'] = false;
		update_option( VARCONV_INSTALL_OPTION, $install );
	}
}

/** Adds the bubble to the Products menu, which WooCommerce registers. Runs after varconv_menu. */
add_action( 'admin_menu', 'varconv_badge_parent', 61 );
function varconv_badge_parent(): void {
	global $menu;
	if ( ! varconv_badge_showing() || ! is_array( $menu ) ) {
		return;
	}
	foreach ( $menu as $i => $item ) {
		if ( isset( $item[2] ) && 'edit.php?post_type=product' === $item[2] ) {
			$menu[ $i ][0] .= varconv_badge_html(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- adds the count bubble, as WordPress does for Plugins.
			return;
		}
	}
}
