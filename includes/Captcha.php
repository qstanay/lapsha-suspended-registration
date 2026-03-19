<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Custom CAPTCHA system.
 *
 * Supports two modes:
 *  – "math"         : simple arithmetic (e.g. «Сколько будет 7 + 3?»)
 *  – "image_select" : pick the correct emoji/icon from a set
 *
 * Challenges are stored server-side in DB (not sessions) and are single-use.
 */
class Captcha {

    public function __construct() {
        if ( '1' !== get_option( 'lapsha_captcha_enabled', '1' ) ) {
            return;
        }

        // Inject CAPTCHA fields into the registration form
        add_action( 'woocommerce_register_form', [ $this, 'render_captcha_field' ] );

        // AJAX endpoint to generate a fresh captcha (no auth required)
        add_action( 'wp_ajax_nopriv_lapsha_captcha', [ $this, 'ajax_generate' ] );
        add_action( 'wp_ajax_lapsha_captcha', [ $this, 'ajax_generate' ] );
    }

    /* ────────────────────────────────────────────────────────
     *  RENDER
     * ──────────────────────────────────────────────────────── */

    /**
     * Render captcha markup inside the registration form.
     */
    public function render_captcha_field() {
        $type = get_option( 'lapsha_captcha_type', 'math' );
        ?>
        <div class="lapsha-captcha-wrap" data-type="<?php echo esc_attr( $type ); ?>">
            <div class="lapsha-captcha-challenge" id="lapsha-captcha-challenge">
                <span class="lapsha-captcha-loading"><?php esc_html_e( 'Загрузка капчи…', 'lapsha-reg' ); ?></span>
            </div>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="lapsha_captcha_answer"><?php esc_html_e( 'Ответ на капчу', 'lapsha-reg' ); ?>&nbsp;<span class="required">*</span></label>
                <input type="text" class="woocommerce-Input input-text" name="lapsha_captcha_answer" id="lapsha_captcha_answer" autocomplete="off" required />
            </p>
            <input type="hidden" name="lapsha_captcha_key" id="lapsha_captcha_key" value="" />
            <button type="button" class="lapsha-captcha-refresh" title="<?php esc_attr_e( 'Обновить капчу', 'lapsha-reg' ); ?>">&#x21bb;</button>
        </div>
        <?php
    }

    /* ────────────────────────────────────────────────────────
     *  GENERATE  (AJAX)
     * ──────────────────────────────────────────────────────── */

    public function ajax_generate() {
        $type = get_option( 'lapsha_captcha_type', 'math' );

        if ( 'image_select' === $type ) {
            $challenge = $this->generate_image_select();
        } else {
            $challenge = $this->generate_math();
        }

        // Store in DB
        $session_key = bin2hex( \random_bytes( 16 ) );
        $expires     = gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS );

        Database::insert_captcha( [
            'session_key'    => $session_key,
            'challenge_data' => $challenge['html'],
            'answer'         => (string) $challenge['answer'],
            'expires_at'     => $expires,
        ] );

        wp_send_json_success( [
            'key'  => $session_key,
            'html' => $challenge['html'],
            'type' => $type,
        ] );
    }

    /* ────────────────────────────────────────────────────────
     *  VERIFY (called from Registration)
     * ──────────────────────────────────────────────────────── */

    /**
     * Verify captcha answer. Single-use: the record is deleted after check.
     */
    public static function verify( string $session_key, string $user_answer ): bool {
        if ( '' === $session_key || '' === $user_answer ) {
            return false;
        }

        $record = Database::get_captcha( $session_key );
        if ( ! $record ) {
            return false;
        }

        // Always delete to prevent reuse
        Database::delete_captcha( $session_key );

        // Constant-time comparison
        return hash_equals( strtolower( trim( $record->answer ) ), strtolower( trim( $user_answer ) ) );
    }

    /* ────────────────────────────────────────────────────────
     *  MATH CHALLENGE
     * ──────────────────────────────────────────────────────── */

    private function generate_math(): array {
        $operators = [ '+', '-', '×' ];
        $op        = $operators[ array_rand( $operators ) ];

        switch ( $op ) {
            case '+':
                $a      = wp_rand( 1, 20 );
                $b      = wp_rand( 1, 20 );
                $answer = $a + $b;
                break;
            case '-':
                $a      = wp_rand( 5, 30 );
                $b      = wp_rand( 1, $a ); // ensure positive result
                $answer = $a - $b;
                break;
            case '×':
                $a      = wp_rand( 2, 9 );
                $b      = wp_rand( 2, 9 );
                $answer = $a * $b;
                break;
        }

        // Render as SVG image to prevent easy parsing by bots
        $svg = $this->math_to_svg( "{$a} {$op} {$b} = ?" );

        $html = sprintf(
            '<div class="lapsha-captcha-math">%s</div>',
            $svg
        );

        return [
            'html'   => $html,
            'answer' => (string) $answer,
        ];
    }

    /**
     * Render math expression as an inline SVG (harder for bots to OCR than plain text).
     */
    private function math_to_svg( string $text ): string {
        $escaped = esc_html( $text );
        $chars   = preg_split( '//u', $escaped, -1, PREG_SPLIT_NO_EMPTY );
        $x       = 10;
        $spans   = '';

        foreach ( $chars as $char ) {
            $rotation = wp_rand( -12, 12 );
            $y_offset = wp_rand( -3, 3 );
            $spans   .= sprintf(
                '<text x="%d" y="%d" transform="rotate(%d %d %d)" class="lapsha-svg-char">%s</text>',
                $x,
                28 + $y_offset,
                $rotation,
                $x,
                28 + $y_offset,
                $char === ' ' ? '&#160;' : esc_html( $char )
            );
            $x += ( $char === ' ' ) ? 12 : wp_rand( 18, 24 );
        }

        // noise lines
        $noise = '';
        for ( $i = 0; $i < 3; $i++ ) {
            $noise .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#ccc" stroke-width="1" opacity="0.5" />',
                wp_rand( 0, 20 ),
                wp_rand( 5, 40 ),
                wp_rand( $x - 30, $x + 10 ),
                wp_rand( 5, 40 )
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="45" viewBox="0 0 %d 45" role="img" aria-label="%s" style="background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
                <style>.lapsha-svg-char{font-family:monospace;font-size:22px;fill:#333;}</style>
                %s%s
            </svg>',
            $x + 15,
            $x + 15,
            esc_attr__( 'Решите пример', 'lapsha-reg' ),
            $noise,
            $spans
        );
    }

    /* ────────────────────────────────────────────────────────
     *  IMAGE SELECT CHALLENGE
     * ──────────────────────────────────────────────────────── */

    private function generate_image_select(): array {
        $groups = [
            'animals'  => [ '🐶', '🐱', '🐭', '🐰', '🦊', '🐻', '🐼', '🐨', '🦁', '🐸' ],
            'fruits'   => [ '🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🫐', '🍒' ],
            'vehicles' => [ '🚗', '🚕', '🚙', '🚌', '🚎', '🏎️', '🚓', '🚑', '🚒', '🛻' ],
            'weather'  => [ '☀️', '🌙', '⭐', '⛅', '🌈', '❄️', '🌧️', '⚡', '🌪️', '🔥' ],
        ];

        $category_names = [
            'animals'  => __( 'животное', 'lapsha-reg' ),
            'fruits'   => __( 'фрукт', 'lapsha-reg' ),
            'vehicles' => __( 'транспорт', 'lapsha-reg' ),
            'weather'  => __( 'погода/стихия', 'lapsha-reg' ),
        ];

        // Pick target category
        $categories    = array_keys( $groups );
        $target_cat    = $categories[ array_rand( $categories ) ];
        $target_emoji  = $groups[ $target_cat ][ array_rand( $groups[ $target_cat ] ) ];

        // Build distractor pool from other categories
        $distractors = [];
        foreach ( $groups as $cat => $emojis ) {
            if ( $cat === $target_cat ) {
                continue;
            }
            $distractors = array_merge( $distractors, $emojis );
        }
        shuffle( $distractors );
        $distractors = array_slice( $distractors, 0, 5 );

        // Place target at random position
        $options = $distractors;
        $target_index = wp_rand( 0, count( $options ) );
        array_splice( $options, $target_index, 0, [ $target_emoji ] );

        // Build HTML
        $question = sprintf(
            /* translators: %s: emoji to find */
            __( 'Нажмите на %s', 'lapsha-reg' ),
            '<span class="lapsha-target-emoji">' . $target_emoji . '</span>'
        );

        $buttons = '';
        foreach ( $options as $i => $emoji ) {
            $buttons .= sprintf(
                '<button type="button" class="lapsha-captcha-option" data-value="%d">%s</button>',
                $i,
                $emoji
            );
        }

        $html = sprintf(
            '<div class="lapsha-captcha-imgsel"><p class="lapsha-captcha-question">%s</p><div class="lapsha-captcha-options">%s</div></div>',
            $question,
            $buttons
        );

        return [
            'html'   => $html,
            'answer' => (string) $target_index,
        ];
    }
}
