<?php
/**
 * Plugin Name: Lapsha Suspended Registration
 * Description: Управление регистрацией WooCommerce: подтверждение email, временные пользователи, собственная капча.
 * Version: 1.0.0-alpha.2
 * Author: Lapsha Dev
 * Author URI: https://lapsha.dev
 * Text Domain: lapsha-reg
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'LAPSHA_REG_VERSION', '1.0.0-alpha.2' );
define( 'LAPSHA_REG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAPSHA_REG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LAPSHA_REG_TABLE_PENDING', 'lapsha_pending_users' );
define( 'LAPSHA_REG_TABLE_CAPTCHA', 'lapsha_captcha_challenges' );

// Autoload classes
spl_autoload_register( function ( $class ) {
    $prefix = 'Lapsha\\Reg\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative_class = substr( $class, strlen( $prefix ) );
    $file = LAPSHA_REG_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/**
 * Activation: create DB tables, schedule cron.
 */
function lapsha_reg_activate() {
    Lapsha\Reg\Database::create_tables();
    if ( ! wp_next_scheduled( 'lapsha_cleanup_pending_users' ) ) {
        wp_schedule_event( time(), 'hourly', 'lapsha_cleanup_pending_users' );
    }
    // Default options
    $defaults = [
        'lapsha_enabled'              => '1',
        'lapsha_email_verification'   => '1',
        'lapsha_pending_ttl_hours'    => '24',
        'lapsha_captcha_enabled'      => '1',
        'lapsha_captcha_type'         => 'math',      // math | image_select
        'lapsha_honeypot_enabled'     => '1',
        'lapsha_rate_limit_enabled'   => '1',
        'lapsha_rate_limit_per_ip'    => '5',          // max attempts per hour
        'lapsha_rate_limit_window'    => '3600',        // seconds
    ];
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value );
        }
    }
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'lapsha_reg_activate' );

/**
 * Deactivation: clear cron.
 */
function lapsha_reg_deactivate() {
    wp_clear_scheduled_hook( 'lapsha_cleanup_pending_users' );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'lapsha_reg_deactivate' );

/**
 * Uninstall is handled via uninstall.php
 */

// Boot the plugin
add_action( 'plugins_loaded', function () {
    // Admin settings page
    if ( is_admin() ) {
        new Lapsha\Reg\Admin();
    }

    // Frontend registration hooks
    new Lapsha\Reg\Registration();

    // Captcha subsystem
    new Lapsha\Reg\Captcha();

    // Rate limiter
    new Lapsha\Reg\RateLimiter();

    // Cron cleanup task
    new Lapsha\Reg\Cron();

    // Email verification endpoint
    new Lapsha\Reg\Verification();

    // Frontend assets
    new Lapsha\Reg\Assets();
} );
