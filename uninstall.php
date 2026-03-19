<?php
/**
 * Uninstall script for Lapsha Suspended Registration.
 *
 * Removes all plugin data: options and custom DB tables.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove options
$options = [
    'lapsha_enabled',
    'lapsha_email_verification',
    'lapsha_pending_ttl_hours',
    'lapsha_captcha_enabled',
    'lapsha_captcha_type',
    'lapsha_honeypot_enabled',
    'lapsha_rate_limit_enabled',
    'lapsha_rate_limit_per_ip',
    'lapsha_rate_limit_window',
    'lapsha_db_version',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Drop custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lapsha_pending_users" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lapsha_captcha_challenges" );

// Clear scheduled cron
wp_clear_scheduled_hook( 'lapsha_cleanup_pending_users' );
