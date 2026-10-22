<?php
/**
 * Removes the plugin's settings and cached scan. Products it made, and the markers on them, stay: deleting the plugin
 * must not change the catalog.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'varconv_dismissed' );
delete_transient( 'varconv_scan' );
