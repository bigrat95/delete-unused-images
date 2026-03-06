<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Delete_Unused_Images
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove all plugin options
delete_option( 'dui_version' );
delete_option( 'dui_scan_results' );
delete_option( 'dui_scan_used_ids' );
delete_option( 'dui_scan_date' );
delete_option( 'dui_whitelist' );
delete_option( 'dui_cron_enabled' );
delete_option( 'dui_cron_frequency' );

// Clear scheduled events
wp_clear_scheduled_hook( 'dui_scheduled_cleanup' );
