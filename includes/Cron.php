<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Cron task: clean up expired pending users and captcha records.
 */
class Cron {

    public function __construct() {
        add_action( 'lapsha_cleanup_pending_users', [ $this, 'run_cleanup' ] );
    }

    /**
     * Delete expired records from both tables.
     */
    public function run_cleanup() {
        Database::delete_expired_pending();
        Database::delete_expired_captchas();
    }
}
