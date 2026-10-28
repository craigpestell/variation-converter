<?php
/**
 * Removes the plugin's settings, cached scan and any bulk job. Products it made, and the markers on them, stay: deleting the plugin
 * must not change the catalog.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'varconv_dismissed' );
delete_option( 'varconv_excluded' );
delete_option( 'varconv_lock' );
delete_option( 'varconv_job' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'varconv_bulk_batch' );
}
delete_transient( 'varconv_scan' );
delete_metadata( 'user', 0, 'varconv_per_page', '', true );
