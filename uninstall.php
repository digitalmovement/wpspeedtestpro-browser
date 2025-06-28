<?php
/**
 * Uninstall script for WP SpeedTest Browser
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('wpstb_version');
delete_option('wpstb_s3_endpoint');
delete_option('wpstb_s3_access_key');
delete_option('wpstb_s3_secret_key');
delete_option('wpstb_s3_bucket');
delete_option('wpstb_last_scan');
delete_option('wpstb_hosting_providers_cache');
delete_option('wpstb_hosting_providers_last_update');

// Remove scheduled events
wp_clear_scheduled_hook('wpstb_update_hosting_providers');

// Optionally remove database tables
// Uncomment the following lines if you want to remove all data on uninstall
/*
global $wpdb;

$tables = array(
    $wpdb->prefix . 'wpstb_bug_reports',
    $wpdb->prefix . 'wpstb_diagnostic_data',
    $wpdb->prefix . 'wpstb_site_plugins',
    $wpdb->prefix . 'wpstb_processed_files',
    $wpdb->prefix . 'wpstb_hosting_providers'
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}
*/ 