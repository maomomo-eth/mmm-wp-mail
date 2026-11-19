<?php
defined( 'ABSPATH' ) || exit;

final class MMM_Mail_Settings {
    const OPTION = 'mmm_mail_settings';

    public static function defaults() {
        $defaults = array( 'provider' => '', 'from_email' => '', 'from_name' => '', 'force_from' => 1,
            'brevo_key' => '', 'resend_key' => '', 'smtp_host' => '', 'smtp_port' => 587,
            'smtp_encryption' => 'tls', 'smtp_auth' => 1, 'smtp_user' => '', 'smtp_password' => '' );
        foreach ( $defaults as $key => $value ) {
            if ( 'force_from' !== $key ) {
                $defaults[ 'backup_' . $key ] = $value;
            }
        }
        return $defaults;
    }

    public static function get() {
        return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
    }

    public static function secret( $field ) {
        $constants = array( 'brevo_key' => 'MMM_MAIL_BREVO_KEY', 'resend_key' => 'MMM_MAIL_RESEND_KEY', 'smtp_password' => 'MMM_MAIL_SMTP_PASSWORD' );
        foreach ( $constants as $key => $constant ) {
            $constants[ 'backup_' . $key ] = str_replace( 'MMM_MAIL_', 'MMM_MAIL_BACKUP_', $constant );
        }
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

    public static function profile( $prefix = '' ) {
        $settings = self::get();
        if ( '' === $prefix ) {
            return $settings;
        }
        $profile = array( 'force_from' => 1 );
        foreach ( $settings as $key => $value ) {
            if ( 0 === strpos( $key, 'backup_' ) ) {
                $profile[ substr( $key, 7 ) ] = $value;
            }
        }
        return $profile;
    }

    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $old = self::get();
        $out = $old;
        $out['force_from'] = empty( $input['force_from'] ) ? 0 : 1;
        foreach ( array( '', 'backup_' ) as $prefix ) {
            $provider = sanitize_key( $input[ $prefix . 'provider' ] ?? '' );
            $out[ $prefix . 'provider' ] = in_array( $provider, array( '', 'brevo', 'resend', 'smtp' ), true ) ? $provider : '';
            $out[ $prefix . 'from_email' ] = sanitize_email( $input[ $prefix . 'from_email' ] ?? '' );
            $out[ $prefix . 'from_name' ] = sanitize_text_field( $input[ $prefix . 'from_name' ] ?? '' );
            $out[ $prefix . 'smtp_host' ] = trim( sanitize_text_field( $input[ $prefix . 'smtp_host' ] ?? '' ) );
            $out[ $prefix . 'smtp_port' ] = max( 1, min( 65535, (int) ( $input[ $prefix . 'smtp_port' ] ?? 587 ) ) );
            $encryption = $input[ $prefix . 'smtp_encryption' ] ?? 'tls';
            $out[ $prefix . 'smtp_encryption' ] = in_array( $encryption, array( 'tls', 'ssl', '' ), true ) ? $encryption : 'tls';
            $out[ $prefix . 'smtp_auth' ] = empty( $input[ $prefix . 'smtp_auth' ] ) ? 0 : 1;
            $out[ $prefix . 'smtp_user' ] = sanitize_text_field( $input[ $prefix . 'smtp_user' ] ?? '' );
        }
        foreach ( array( 'brevo_key', 'resend_key', 'smtp_password', 'backup_brevo_key', 'backup_resend_key', 'backup_smtp_password' ) as $field ) {
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
        foreach ( array( '' => '主用', 'backup_' => '备用' ) as $prefix => $label ) {
            if ( $out[ $prefix . 'provider' ] && ! is_email( $out[ $prefix . 'from_email' ] ) ) {
                add_settings_error( self::OPTION, $prefix . 'sender', $label . '通道需要有效的发件人邮箱，配置未保存。' );
                return $old;
            }
            // 禁止 PHPMailer 的多主机、URL 或命令式主机配置。
            if ( 'smtp' === $out[ $prefix . 'provider' ] && ! preg_match( '/\A[a-zA-Z0-9.\-]+\z/', $out[ $prefix . 'smtp_host' ] ) ) {
                add_settings_error( self::OPTION, $prefix . 'host', $label . ' SMTP 主机只能填写单个域名或 IPv4 地址，配置未保存。' );
                return $old;
            }
        }
        return $out;
    }
}
