<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limiter: prevents brute-force registration from a single IP.
 * Also injects honeypot field into the registration form.
 */
class RateLimiter {

    public function __construct() {
        if ( '1' !== get_option( 'lapsha_enabled', '1' ) ) {
            return;
        }

        // Honeypot field
        if ( '1' === get_option( 'lapsha_honeypot_enabled', '1' ) ) {
            add_action( 'woocommerce_register_form', [ $this, 'render_honeypot' ] );
        }
    }

    /**
     * Render an invisible honeypot field.
     * Bots tend to fill every field; real users can't see this.
     */
    public function render_honeypot() {
        ?>
        <div class="lapsha-hp-field" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
            <label for="lapsha_website_url"><?php esc_html_e( 'Website', 'lapsha-reg' ); ?></label>
            <input type="text" name="lapsha_website_url" id="lapsha_website_url" value="" tabindex="-1" autocomplete="off" />
        </div>
        <?php
    }
}
