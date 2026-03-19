<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Core registration logic:
 *  – intercepts the WooCommerce AJAX registration (ajaxreg)
 *  – stores the user in a pending table
 *  – sends confirmation email
 */
class Registration {

    public function __construct() {
        if ( '1' !== get_option( 'lapsha_enabled', '1' ) ) {
            return;
        }

        // Replace the default ajaxreg handler with our own
        add_action( 'init', [ $this, 'override_ajax_handler' ], 1 );
    }

    /**
     * Remove the theme's ajax_reg and replace with ours.
     */
    public function override_ajax_handler() {
        if ( is_user_logged_in() ) {
            return;
        }

        // Remove original handler added by the theme
        remove_action( 'wp_ajax_nopriv_ajaxreg', 'ajax_reg' );

        // Add our handler
        add_action( 'wp_ajax_nopriv_ajaxreg', [ $this, 'handle_registration' ] );
    }

    /**
     * Handle AJAX registration request.
     */
    public function handle_registration() {
        check_ajax_referer( 'ajax-reg-nonce', 'security' );

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        // ── Basic validation ──
        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json( [
                'code'    => 'error',
                'message' => __( 'Пожалуйста, введите корректный email.', 'lapsha-reg' ),
            ] );
        }

        // ── Honeypot check ──
        if ( '1' === get_option( 'lapsha_honeypot_enabled', '1' ) ) {
            $honeypot = isset( $_POST['lapsha_website_url'] ) ? sanitize_text_field( wp_unslash( $_POST['lapsha_website_url'] ) ) : '';
            if ( '' !== $honeypot ) {
                // Bot filled the hidden field – silently pretend success
                wp_send_json( [
                    'code'     => 200,
                    'message'  => __( 'Проверьте свою почту для подтверждения.', 'lapsha-reg' ),
                    'loggedin' => false,
                ] );
            }
        }

        // ── Rate limiter ──
        if ( '1' === get_option( 'lapsha_rate_limit_enabled', '1' ) ) {
            $ip     = self::get_client_ip();
            $limit  = (int) get_option( 'lapsha_rate_limit_per_ip', 5 );
            $window = (int) get_option( 'lapsha_rate_limit_window', 3600 );

            if ( Database::count_recent_by_ip( $ip, $window ) >= $limit ) {
                wp_send_json( [
                    'code'    => 'error',
                    'message' => __( 'Слишком много попыток регистрации. Попробуйте позже.', 'lapsha-reg' ),
                ] );
            }
        }

        // ── Captcha validation ──
        if ( '1' === get_option( 'lapsha_captcha_enabled', '1' ) ) {
            $captcha_key    = isset( $_POST['lapsha_captcha_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lapsha_captcha_key'] ) ) : '';
            $captcha_answer = isset( $_POST['lapsha_captcha_answer'] ) ? sanitize_text_field( wp_unslash( $_POST['lapsha_captcha_answer'] ) ) : '';

            if ( ! Captcha::verify( $captcha_key, $captcha_answer ) ) {
                wp_send_json( [
                    'code'    => 'error',
                    'message' => __( 'Неверный ответ на капчу. Попробуйте ещё раз.', 'lapsha-reg' ),
                ] );
            }
        }

        // ── Check if email already registered as a real WP user ──
        if ( email_exists( $email ) ) {
            wp_send_json( [
                'code'    => 'error',
                'message' => __( 'Пользователь с таким email уже зарегистрирован.', 'lapsha-reg' ),
            ] );
        }

        // ── WooCommerce validation filters (compatibility) ──
        $username = '';
        $password = '';

        if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) {
            $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
        }
        if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) {
            $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore -- raw password for hashing
        }

        $validation_error = new \WP_Error();
        $validation_error = apply_filters( 'woocommerce_process_registration_errors', $validation_error, $username, $password, $email );

        if ( $validation_error->get_error_code() ) {
            wp_send_json( [
                'code'    => $validation_error->get_error_code(),
                'message' => $validation_error->get_error_message(),
            ] );
        }

        // ── Email verification enabled? ──
        if ( '1' === get_option( 'lapsha_email_verification', '1' ) ) {
            $this->create_pending_user( $email, $username, $password );
        } else {
            // Fallback: create user immediately (legacy behaviour)
            $this->create_user_now( $email, $username, $password );
        }
    }

    /**
     * Store pending user and send verification email.
     */
    private function create_pending_user( string $email, string $username, string $password ) {
        // Remove existing pending record for this email (re-registration)
        $existing = Database::get_pending_by_email( $email );
        if ( $existing ) {
            Database::delete_pending( (int) $existing->id );
        }

        $token    = bin2hex( \random_bytes( 32 ) );
        $ttl      = (int) get_option( 'lapsha_pending_ttl_hours', 24 );
        $expires  = gmdate( 'Y-m-d H:i:s', time() + $ttl * HOUR_IN_SECONDS );

        $password_hash = '';
        if ( '' !== $password ) {
            $password_hash = wp_hash_password( $password );
        }

        $result = Database::insert_pending_user( [
            'email'         => $email,
            'username'      => $username,
            'password_hash' => $password_hash,
            'token'         => $token,
            'ip_address'    => self::get_client_ip(),
            'expires_at'    => $expires,
        ] );

        if ( ! $result ) {
            wp_send_json( [
                'code'    => 'error',
                'message' => __( 'Ошибка при сохранении заявки. Попробуйте ещё раз.', 'lapsha-reg' ),
            ] );
        }

        // Send verification email
        $this->send_verification_email( $email, $token );

        wp_send_json( [
            'code'     => 200,
            'message'  => __( 'На вашу почту отправлено письмо с подтверждением. Проверьте входящие (и спам).', 'lapsha-reg' ),
            'loggedin' => false,
            'pending'  => true,
        ] );
    }

    /**
     * Immediately create user (when email verification is off).
     */
    private function create_user_now( string $email, string $username, string $password ) {
        if ( function_exists( 'wc_create_new_customer' ) ) {
            $new_customer = wc_create_new_customer( $email, $username ?: '', $password ?: '' );
        } else {
            $new_customer = new \WP_Error( 'wc_missing', 'WooCommerce not available' );
        }

        if ( is_wp_error( $new_customer ) ) {
            wp_send_json( [
                'code'    => $new_customer->get_error_code(),
                'message' => $new_customer->get_error_message(),
            ] );
        }

        if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
            if ( apply_filters( 'woocommerce_registration_auth_new_customer', true, $new_customer ) ) {
                wc_set_customer_auth_cookie( $new_customer );
            }
        }

        wp_send_json( [
            'code'     => 200,
            'message'  => __( 'Аккаунт успешно создан. Редиректим...', 'lapsha-reg' ),
            'loggedin' => true,
        ] );
    }

    /**
     * Send verification email with confirmation link.
     */
    private function send_verification_email( string $email, string $token ) {
        $confirm_url = add_query_arg(
            [
                'lapsha_action' => 'verify',
                'token'         => $token,
            ],
            home_url( '/' )
        );

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf(
            /* translators: %s: site name */
            __( '[%s] Подтвердите email для завершения регистрации', 'lapsha-reg' ),
            $site_name
        );

        $message  = sprintf( __( 'Здравствуйте!', 'lapsha-reg' ) ) . "\n\n";
        $message .= sprintf(
            __( 'Вы зарегистрировались на сайте %s. Для подтверждения email перейдите по ссылке:', 'lapsha-reg' ),
            $site_name
        ) . "\n\n";
        $message .= $confirm_url . "\n\n";
        $message .= sprintf(
            __( 'Ссылка действительна %d ч. Если вы не регистрировались — просто проигнорируйте это письмо.', 'lapsha-reg' ),
            (int) get_option( 'lapsha_pending_ttl_hours', 24 )
        ) . "\n";

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        wp_mail( $email, $subject, $message, $headers );
    }

    /**
     * Get the real client IP (respects proxies cautiously).
     */
    public static function get_client_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '127.0.0.1';
    }
}
