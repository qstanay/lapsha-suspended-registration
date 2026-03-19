<?php

namespace Lapsha\Reg;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page – available only for administrators.
 */
class Admin {

    private const OPTION_GROUP = 'lapsha_reg_options';
    private const PAGE_SLUG    = 'lapsha-reg-settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_menu_page() {
        add_options_page(
            __( 'Suspended Registration', 'lapsha-reg' ),
            __( 'Lapsha Reg', 'lapsha-reg' ),
            'manage_options', // admins only
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        // ─── Section: General ───
        add_settings_section( 'lapsha_general', __( 'Основные настройки', 'lapsha-reg' ), '__return_false', self::PAGE_SLUG );

        $this->add_field( 'lapsha_enabled', __( 'Плагин активен', 'lapsha-reg' ), 'checkbox', 'lapsha_general' );
        $this->add_field( 'lapsha_email_verification', __( 'Подтверждение email', 'lapsha-reg' ), 'checkbox', 'lapsha_general', __( 'Новые пользователи должны подтвердить email перед созданием аккаунта.', 'lapsha-reg' ) );
        $this->add_field( 'lapsha_pending_ttl_hours', __( 'Время жизни заявки (часы)', 'lapsha-reg' ), 'number', 'lapsha_general', __( 'Неподтверждённые регистрации удаляются через указанное время.', 'lapsha-reg' ) );

        // ─── Section: Anti-Spam ───
        add_settings_section( 'lapsha_antispam', __( 'Защита от спама', 'lapsha-reg' ), '__return_false', self::PAGE_SLUG );

        $this->add_field( 'lapsha_captcha_enabled', __( 'Капча включена', 'lapsha-reg' ), 'checkbox', 'lapsha_antispam' );
        $this->add_field( 'lapsha_captcha_type', __( 'Тип капчи', 'lapsha-reg' ), 'select', 'lapsha_antispam', '', [
            'math'         => __( 'Математический пример', 'lapsha-reg' ),
            'image_select' => __( 'Выбор изображения', 'lapsha-reg' ),
        ] );
        $this->add_field( 'lapsha_honeypot_enabled', __( 'Honeypot (скрытое поле)', 'lapsha-reg' ), 'checkbox', 'lapsha_antispam', __( 'Невидимое поле-ловушка для ботов.', 'lapsha-reg' ) );
        $this->add_field( 'lapsha_rate_limit_enabled', __( 'Ограничение по IP', 'lapsha-reg' ), 'checkbox', 'lapsha_antispam' );
        $this->add_field( 'lapsha_rate_limit_per_ip', __( 'Макс. попыток с одного IP', 'lapsha-reg' ), 'number', 'lapsha_antispam' );
        $this->add_field( 'lapsha_rate_limit_window', __( 'Окно (секунды)', 'lapsha-reg' ), 'number', 'lapsha_antispam' );
    }

    /* ─── Helper: register + render a field ─── */

    private function add_field( string $option, string $label, string $type, string $section, string $description = '', array $choices = [] ) {
        register_setting( self::OPTION_GROUP, $option, [
            'sanitize_callback' => [ $this, 'sanitize_' . $type ],
        ] );

        add_settings_field( $option, $label, function () use ( $option, $type, $description, $choices ) {
            $value = get_option( $option );
            switch ( $type ) {
                case 'checkbox':
                    printf(
                        '<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> ',
                        esc_attr( $option ),
                        checked( $value, '1', false )
                    );
                    break;
                case 'number':
                    printf(
                        '<input type="number" id="%1$s" name="%1$s" value="%2$s" min="1" class="small-text" />',
                        esc_attr( $option ),
                        esc_attr( $value )
                    );
                    break;
                case 'select':
                    printf( '<select id="%1$s" name="%1$s">', esc_attr( $option ) );
                    foreach ( $choices as $k => $v ) {
                        printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $value, $k, false ), esc_html( $v ) );
                    }
                    echo '</select>';
                    break;
            }
            if ( $description ) {
                printf( '<p class="description">%s</p>', esc_html( $description ) );
            }
        }, self::PAGE_SLUG, $section );
    }

    /* ─── Sanitizers ─── */

    public function sanitize_checkbox( $value ) {
        return '1' === (string) $value ? '1' : '0';
    }

    public function sanitize_number( $value ) {
        return absint( $value );
    }

    public function sanitize_select( $value ) {
        return sanitize_key( $value );
    }

    /* ─── Render page ─── */

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'У вас нет прав для просмотра этой страницы.', 'lapsha-reg' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( self::OPTION_GROUP );
                    do_settings_sections( self::PAGE_SLUG );
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
