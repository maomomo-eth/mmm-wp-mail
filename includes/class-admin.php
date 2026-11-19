<?php
defined( 'ABSPATH' ) || exit;

final class MMM_Mail_Admin {
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
        add_action( 'admin_post_mmm_mail_test', array( __CLASS__, 'test' ) );
        add_action( 'admin_post_mmm_mail_check_update', array( __CLASS__, 'check_update' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( MMM_MAIL_FILE ), static function ( $links ) {
            array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=mmm-wp-mail' ) ) . '">设置</a>' );
            return $links;
        } );
    }

    public static function menu() {
        add_options_page( 'mmm-wp-mail', 'mmm-wp-mail', 'manage_options', 'mmm-wp-mail', array( __CLASS__, 'page' ) );
    }

    public static function register() {
        register_setting( 'mmm_mail', MMM_Mail_Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( 'MMM_Mail_Settings', 'sanitize' ), 'show_in_rest' => false ) );
        // 密钥选项不自动加载到每个前台请求。
        add_option( MMM_Mail_Settings::OPTION, MMM_Mail_Settings::defaults(), '', false );
    }

    private static function authorize( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '没有操作权限。', '', array( 'response' => 403 ) );
        }
        check_admin_referer( $nonce );
    }

    private static function back( $notice, $success ) {
        set_transient( 'mmm_mail_notice_' . get_current_user_id(), array( 'text' => $notice, 'success' => $success ), 60 );
        wp_safe_redirect( admin_url( 'options-general.php?page=mmm-wp-mail' ) );
        exit;
    }

    public static function test() {
        self::authorize( 'mmm_mail_test' );
        $to = sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) );
        if ( ! is_email( $to ) || ! MMM_Mail_Settings::get()['provider'] ) {
            self::back( '请先保存发送配置，再填写有效的测试收件地址。', false );
        }
        $error = '';
        $capture = static function ( $failure ) use ( &$error ) { $error = $failure->get_error_message(); };
        add_action( 'wp_mail_failed', $capture );
        $sent = wp_mail( $to, 'mmm-wp-mail 测试邮件', '<p>这是一封来自 ' . esc_html( get_bloginfo( 'name' ) ) . ' 的测试邮件。</p><p>当前时间：' . esc_html( current_time( 'mysql' ) ) . '</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
        remove_action( 'wp_mail_failed', $capture );
        // 即使其他插件的错误信息含有密钥，也不回显完整凭据。
        foreach ( array( 'brevo_key', 'resend_key', 'smtp_password' ) as $field ) {
            $secret = MMM_Mail_Settings::secret( $field );
            if ( '' !== $secret ) {
                $error = str_replace( $secret, '[已隐藏]', $error );
            }
        }
        self::back( $sent ? '服务商已接收测试邮件，请检查收件箱和垃圾箱。' : '发送失败：' . ( $error ?: '请检查配置及其他邮件插件。' ), (bool) $sent );
    }

    public static function check_update() {
        self::authorize( 'mmm_mail_check_update' );
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( '没有更新插件的权限。', '', array( 'response' => 403 ) );
        }
        delete_site_transient( MMM_Mail_Updater::CACHE );
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_clean_plugins_cache( true );
        wp_update_plugins();
        $release = MMM_Mail_Updater::release();
        if ( ! $release ) {
            self::back( '未获取到正式 Release，请检查 GitHub 连接或稍后重试。', false );
        }
        self::back( version_compare( $release['version'], MMM_MAIL_VERSION, '>' ) ? '发现新版本，请到“插件”页面点击“立即更新”。' : '当前已经是最新正式版本。', true );
    }

    private static function input( $key, $label, $type = 'text', $description = '' ) {
        $s = MMM_Mail_Settings::get();
        $secret = 'password' === $type;
        echo '<tr><th scope="row"><label for="mmm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
        echo '<input class="regular-text" id="mmm-' . esc_attr( $key ) . '" name="' . esc_attr( MMM_Mail_Settings::OPTION . '[' . $key . ']' ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $secret ? '' : $s[ $key ] ) . '" autocomplete="' . ( $secret ? 'new-password' : 'off' ) . '"' . ( 'number' === $type ? ' min="1" max="65535"' : '' ) . '>';
        if ( $secret ) {
            echo '<p class="description">' . ( '' !== MMM_Mail_Settings::secret( $key ) ? '已配置；留空保留原值。' : '尚未配置。' ) . ' <label><input type="checkbox" name="' . esc_attr( MMM_Mail_Settings::OPTION . '[clear_' . $key . ']' ) . '" value="1">清除后台保存的值</label></p>';
        }
        if ( $description ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function checkbox( $key, $label ) {
        $s = MMM_Mail_Settings::get();
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( MMM_Mail_Settings::OPTION . '[' . $key . ']' ) . '" value="1" ' . checked( $s[ $key ], 1, false ) . '>启用</label></td></tr>';
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = MMM_Mail_Settings::get();
        echo '<div class="wrap"><h1>mmm-wp-mail</h1><p>选择一种发送方式并保存，再发送测试邮件。请停用其他接管邮件发送的插件。</p>';
        settings_errors();
        $notice = get_transient( 'mmm_mail_notice_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'mmm_mail_notice_' . get_current_user_id() );
            echo '<div class="notice ' . ( $notice['success'] ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
        }
        echo '<form method="post" action="options.php">';
        settings_fields( 'mmm_mail' );
        echo '<table class="form-table" role="presentation"><tr><th><label for="mmm-provider">发送方式</label></th><td><select id="mmm-provider" name="' . esc_attr( MMM_Mail_Settings::OPTION ) . '[provider]">';
        foreach ( array( '' => '不接管（使用 WordPress 默认方式）', 'brevo' => 'Brevo API', 'resend' => 'Resend API', 'smtp' => '普通 SMTP' ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $s['provider'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';
        self::input( 'from_email', '发件人邮箱', 'email', 'Brevo / Resend 需要先在服务商后台验证发件人或域名。' );
        self::input( 'from_name', '发件人名称' );
        self::checkbox( 'force_from', '强制使用上述发件人' );
        echo '</table><div data-provider="brevo"><h2>Brevo API</h2><table class="form-table" role="presentation">';
        self::input( 'brevo_key', 'API Key', 'password' );
        echo '</table></div><div data-provider="resend"><h2>Resend API</h2><table class="form-table" role="presentation">';
        self::input( 'resend_key', 'API Key', 'password' );
        echo '</table></div><div data-provider="smtp"><h2>普通 SMTP</h2><table class="form-table" role="presentation">';
        self::input( 'smtp_host', 'SMTP 主机', 'text', '例如 smtp.example.com，不含协议和端口。' );
        self::input( 'smtp_port', '端口', 'number', 'STARTTLS 通常用 587，SSL/TLS 通常用 465。' );
        echo '<tr><th><label for="mmm-encryption">加密方式</label></th><td><select id="mmm-encryption" name="' . esc_attr( MMM_Mail_Settings::OPTION ) . '[smtp_encryption]">';
        foreach ( array( 'tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', '' => '无加密（仅可信内网）' ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $s['smtp_encryption'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';
        self::checkbox( 'smtp_auth', 'SMTP 身份认证' );
        self::input( 'smtp_user', '用户名' );
        self::input( 'smtp_password', '密码 / 授权码', 'password' );
        echo '</table></div>';
        submit_button( '保存设置' );
        echo '</form><hr><h2>发送测试邮件</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mmm_mail_test">';
        wp_nonce_field( 'mmm_mail_test' );
        echo '<label for="mmm-recipient">收件地址 </label><input class="regular-text" id="mmm-recipient" type="email" name="recipient" required value="' . esc_attr( wp_get_current_user()->user_email ) . '">';
        submit_button( '发送测试邮件', 'secondary', 'mmm_mail_test_submit' );
        echo '</form><hr><h2>GitHub 更新</h2><p>当前版本：' . esc_html( MMM_MAIL_VERSION ) . '。正式 Release 可在插件页面一键更新。</p>';
        if ( current_user_can( 'update_plugins' ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mmm_mail_check_update">';
            wp_nonce_field( 'mmm_mail_check_update' );
            submit_button( '检查更新', 'secondary', 'mmm_mail_update_submit' );
            echo '</form>';
        }
        echo '</div><script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("mmm-provider");function show(){document.querySelectorAll("[data-provider]").forEach(function(e){e.hidden=e.dataset.provider!==s.value;});}s.addEventListener("change",show);show();});</script>';
    }
}
