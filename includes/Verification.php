<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Email verification endpoint.
 *
 * Handles ?lapsha_action=verify&token=…
 * On success: creates the real WP/WooCommerce user, logs them in, redirects to My Account.
 */
class Verification {

    public function __construct() {
        add_action( 'template_redirect', [ $this, 'handle_verify' ] );
    }

    public function handle_verify() {
        if ( empty( $_GET['lapsha_action'] ) || 'verify' !== $_GET['lapsha_action'] ) {
            return;
        }

        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        if ( '' === $token || strlen( $token ) !== 64 || ! ctype_xdigit( $token ) ) {
            wp_die(
                esc_html__( 'Неверная ссылка подтверждения.', 'lapsha-reg' ),
                esc_html__( 'Ошибка', 'lapsha-reg' ),
                [ 'response' => 400 ]
            );
        }

        $pending = Database::get_pending_by_token( $token );

        if ( ! $pending ) {
            wp_die(
                esc_html__( 'Ссылка недействительна или истекла. Пожалуйста, зарегистрируйтесь повторно.', 'lapsha-reg' ),
                esc_html__( 'Срок действия истёк', 'lapsha-reg' ),
                [ 'response' => 410 ]
            );
        }

        // Double-check: email still not taken
        if ( email_exists( $pending->email ) ) {
            Database::delete_pending( (int) $pending->id );
            wp_die(
                esc_html__( 'Этот email уже зарегистрирован. Попробуйте войти.', 'lapsha-reg' ),
                esc_html__( 'Уже существует', 'lapsha-reg' ),
                [ 'response' => 409 ]
            );
        }

        // ── Create the real WP/WC user ──
        $password = '';
        if ( '' !== $pending->password_hash ) {
            $password = wp_generate_password( 24, true, true ); // temp, we'll overwrite the hash
        }

        if ( function_exists( 'wc_create_new_customer' ) ) {
            $user_id = wc_create_new_customer(
                $pending->email,
                $pending->username ?: '',
                $password
            );
        } else {
            $user_id = wp_create_user(
                $pending->username ?: $pending->email,
                $password ?: wp_generate_password(),
                $pending->email
            );
        }

        if ( is_wp_error( $user_id ) ) {
            wp_die(
                esc_html( $user_id->get_error_message() ),
                esc_html__( 'Ошибка', 'lapsha-reg' ),
                [ 'response' => 500 ]
            );
        }

        // If the user originally provided their own password, overwrite the hash directly
        if ( '' !== $pending->password_hash ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->users,
                [ 'user_pass' => $pending->password_hash ],
                [ 'ID' => $user_id ],
                [ '%s' ],
                [ '%d' ]
            );
            clean_user_cache( $user_id );
        }

        // Remove pending record
        Database::delete_pending( (int) $pending->id );

        // Auto-login
        if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
            wc_set_customer_auth_cookie( $user_id );
        } else {
            wp_set_auth_cookie( $user_id, true );
            wp_set_current_user( $user_id );
        }

        // Redirect to My Account (or homepage)
        $redirect = function_exists( 'wc_get_page_permalink' )
            ? wc_get_page_permalink( 'myaccount' )
            : home_url( '/' );

        wp_safe_redirect( $redirect );
        exit;
    }
}
