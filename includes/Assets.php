<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue frontend CSS & JS assets.
 */
class Assets {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue() {
        if ( is_user_logged_in() || '1' !== get_option( 'lapsha_enabled', '1' ) ) {
            return;
        }

        wp_enqueue_style(
            'lapsha-reg-front',
            LAPSHA_REG_PLUGIN_URL . 'assets/css/lapsha-reg-front.css',
            [],
            LAPSHA_REG_VERSION
        );

        wp_enqueue_script(
            'lapsha-reg-front',
            LAPSHA_REG_PLUGIN_URL . 'assets/js/lapsha-reg-front.js',
            [ 'jquery' ],
            LAPSHA_REG_VERSION,
            true
        );

        wp_localize_script( 'lapsha-reg-front', 'lapshaReg', [
            'pendingUrl' => add_query_arg( 'lapsha_pending', '1', wc_get_page_permalink( 'myaccount' ) ),
        ] );
    }
}
