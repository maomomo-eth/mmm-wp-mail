<?php
defined( 'ABSPATH' ) || exit;

final class MMM_Mail_Settings {
    const OPTION = 'mmm_mail_settings';

    public static function defaults() {
        return array( 'provider' => '', 'from_email' => '', 'from_name' => '', 'force_from' => 1,
            'brevo_key' => '', 'resend_key' => '', 'smtp_host' => '', 'smtp_port' => 587,
            'smtp_encryption' => 'tls', 'smtp_auth' => 1, 'smtp_user' => '', 'smtp_password' => '' );
    }

    public static function get() {
        return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
    }

    public static function secret( $field ) {
        $constants = array( 'brevo_key' => 'MMM_MAIL_BREVO_KEY', 'resend_key' => 'MMM_MAIL_RESEND_KEY', 'smtp_password' => 'MMM_MAIL_SMTP_PASSWORD' );
        if ( isset( $constants[ $field ] ) && defined( $constants[ $field ] ) ) {
            return (string) constant( $constants[ $field ] );
        }
        $settings = self::get();
        $encoded = $settings[ $field ] ?? '';
        if ( ! $encoded || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $data = base64_decode( $encoded, true );
        if ( false === $data || strlen( $data ) < 29 ) {
            return '';
        }
        $plain = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) );
        return false === $plain ? '' : $plain;
    }

    private static function key() {
        return hash( 'sha256', wp_salt( 'auth' ) . 'mmm-wp-mail', true );
    }

    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $old = self::get();
        $out = $old;
        $provider = sanitize_key( $input['provider'] ?? '' );
        $out['provider'] = in_array( $provider, array( '', 'brevo', 'resend', 'smtp' ), true ) ? $provider : '';
        $out['from_email'] = sanitize_email( $input['from_email'] ?? '' );
        $out['from_name'] = sanitize_text_field( $input['from_name'] ?? '' );
        $out['force_from'] = empty( $input['force_from'] ) ? 0 : 1;
        $out['smtp_host'] = trim( sanitize_text_field( $input['smtp_host'] ?? '' ) );
        $out['smtp_port'] = max( 1, min( 65535, (int) ( $input['smtp_port'] ?? 587 ) ) );
        $out['smtp_encryption'] = in_array( $input['smtp_encryption'] ?? '', array( 'tls', 'ssl', '' ), true ) ? $input['smtp_encryption'] : 'tls';
        $out['smtp_auth'] = empty( $input['smtp_auth'] ) ? 0 : 1;
        $out['smtp_user'] = sanitize_text_field( $input['smtp_user'] ?? '' );
        foreach ( array( 'brevo_key', 'resend_key', 'smtp_password' ) as $field ) {
            if ( ! empty( $input[ 'clear_' . $field ] ) ) {
                $out[ $field ] = '';
            } elseif ( isset( $input[ $field ] ) && is_string( $input[ $field ] ) && '' !== $input[ $field ] ) {
                if ( ! function_exists( 'openssl_encrypt' ) ) {
                    add_settings_error( self::OPTION, 'openssl', '保存密钥需要 PHP OpenSSL 扩展，也可以在 wp-config.php 中定义密钥常量。' );
                    return $old;
                }
                $iv = random_bytes( 12 );
                $tag = '';
                $cipher = openssl_encrypt( $input[ $field ], 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
                if ( false === $cipher ) {
                    add_settings_error( self::OPTION, 'encrypt', '密钥加密失败，配置未保存。' );
                    return $old;
                }
                $out[ $field ] = base64_encode( $iv . $tag . $cipher );
            }
        }
        if ( $out['provider'] && ! is_email( $out['from_email'] ) ) {
            add_settings_error( self::OPTION, 'sender', '请填写有效的发件人邮箱，配置未保存。' );
            return $old;
        }
        // 禁止 PHPMailer 的多主机、URL 或命令式主机配置。
        if ( 'smtp' === $out['provider'] && ! preg_match( '/\A[a-zA-Z0-9.\-]+\z/', $out['smtp_host'] ) ) {
            add_settings_error( self::OPTION, 'host', 'SMTP 主机只能填写单个域名或 IPv4 地址，配置未保存。' );
            return $old;
        }
        return $out;
    }
}
