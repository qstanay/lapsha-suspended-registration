<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Database management: table creation and helper queries.
 */
class Database {

    /**
     * Create plugin tables using dbDelta.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $pending_table = $wpdb->prefix . LAPSHA_REG_TABLE_PENDING;
        $captcha_table = $wpdb->prefix . LAPSHA_REG_TABLE_CAPTCHA;

        $sql = "CREATE TABLE {$pending_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(100) NOT NULL,
            username VARCHAR(60) NOT NULL DEFAULT '',
            password_hash VARCHAR(255) NOT NULL DEFAULT '',
            token VARCHAR(64) NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            UNIQUE KEY email (email),
            KEY expires_at (expires_at)
        ) {$charset_collate};

        CREATE TABLE {$captcha_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(64) NOT NULL,
            challenge_data TEXT NOT NULL,
            answer VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_key (session_key),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'lapsha_db_version', LAPSHA_REG_VERSION );
    }

    /**
     * Insert a pending user record.
     *
     * @return int|false  Inserted row ID or false on failure.
     */
    public static function insert_pending_user( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_PENDING;

        $result = $wpdb->insert(
            $table,
            [
                'email'         => $data['email'],
                'username'      => $data['username'] ?? '',
                'password_hash' => $data['password_hash'] ?? '',
                'token'         => $data['token'],
                'ip_address'    => $data['ip_address'] ?? '',
                'created_at'    => current_time( 'mysql' ),
                'expires_at'    => $data['expires_at'],
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return false !== $result ? $wpdb->insert_id : false;
    }

    /**
     * Get pending user by confirmation token.
     */
    public static function get_pending_by_token( string $token ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_PENDING;

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s AND expires_at > %s LIMIT 1", $token, current_time( 'mysql' ) )
        );
    }

    /**
     * Get pending user by email.
     */
    public static function get_pending_by_email( string $email ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_PENDING;

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", $email )
        );
    }

    /**
     * Delete a pending user record.
     */
    public static function delete_pending( int $id ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_PENDING;

        return $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Delete expired pending users.
     *
     * @return int|false  Number of rows deleted or false on error.
     */
    public static function delete_expired_pending() {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_PENDING;

        return $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %s", current_time( 'mysql' ) )
        );
    }

    /**
     * Count registrations from a given IP in the last N seconds.
     */
    public static function count_recent_by_ip( string $ip, int $window_seconds ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_PENDING;
        $since = gmdate( 'Y-m-d H:i:s', time() - $window_seconds );

        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND created_at >= %s", $ip, $since )
        );
    }

    /* ─── Captcha helpers ─── */

    public static function insert_captcha( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_CAPTCHA;

        $wpdb->insert(
            $table,
            [
                'session_key'    => $data['session_key'],
                'challenge_data' => $data['challenge_data'],
                'answer'         => $data['answer'],
                'created_at'     => current_time( 'mysql' ),
                'expires_at'     => $data['expires_at'],
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        return $wpdb->insert_id;
    }

    public static function get_captcha( string $session_key ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_CAPTCHA;

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s AND expires_at > %s LIMIT 1", $session_key, current_time( 'mysql' ) )
        );
    }

    public static function delete_captcha( string $session_key ) {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_CAPTCHA;

        return $wpdb->delete( $table, [ 'session_key' => $session_key ], [ '%s' ] );
    }

    public static function delete_expired_captchas() {
        global $wpdb;
        $table = $wpdb->prefix . LAPSHA_REG_TABLE_CAPTCHA;

        return $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %s", current_time( 'mysql' ) )
        );
    }
}
