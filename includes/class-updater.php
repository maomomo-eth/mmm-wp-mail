<?php
defined( 'ABSPATH' ) || exit;

final class MMM_Mail_Updater {
    const REPO = 'maomomo-eth/mmm-wp-mail';
    const CACHE = 'mmm_mail_github_release';

    public static function boot() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 2 );
    }

    public static function release() {
        $cached = get_site_transient( self::CACHE );
        if ( false !== $cached ) {
            return $cached;
        }
        $headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'mmm-wp-mail/' . MMM_MAIL_VERSION );
        if ( defined( 'MMM_MAIL_GITHUB_TOKEN' ) && '' !== MMM_MAIL_GITHUB_TOKEN ) {
            $headers['Authorization'] = 'Bearer ' . MMM_MAIL_GITHUB_TOKEN;
        }
        $response = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array(
            'timeout' => 8, 'redirection' => 0, 'headers' => $headers,
        ) );
        $data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        $result = array();
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && is_array( $data ) && empty( $data['draft'] ) && empty( $data['prerelease'] ) && preg_match( '/\Av?(\d+\.\d+\.\d+)\z/', $data['tag_name'] ?? '', $match ) ) {
            $tag = $data['tag_name'];
            $expected = 'https://github.com/' . self::REPO . '/releases/download/' . $tag . '/mmm-wp-mail.zip';
            foreach ( $data['assets'] ?? array() as $asset ) {
                if ( 'mmm-wp-mail.zip' === ( $asset['name'] ?? '' ) && $expected === ( $asset['browser_download_url'] ?? '' ) ) {
                    $requirements = array( 'requires' => '6.8', 'requires_php' => '7.4' );
                    if ( preg_match( '/<!-- mmm-wp-mail: (\{[^\r\n]+\}) -->/', $data['body'] ?? '', $meta ) ) {
                        $decoded = json_decode( $meta[1], true );
                        foreach ( array_keys( $requirements ) as $key ) {
                            if ( isset( $decoded[ $key ] ) && preg_match( '/\A\d+\.\d+(?:\.\d+)?\z/', $decoded[ $key ] ) ) {
                                $requirements[ $key ] = $decoded[ $key ];
                            }
                        }
                    }
                    $result = array_merge( $requirements, array( 'version' => $match[1], 'package' => $expected,
                        'url' => 'https://github.com/' . self::REPO, 'body' => (string) ( $data['body'] ?? '' ),
                        'published' => (string) ( $data['published_at'] ?? '' ) ) );
                    break;
                }
            }
        }
        set_site_transient( self::CACHE, $result, $result ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
        return $result;
    }

    public static function update( $update, $plugin_data, $plugin_file, $locales ) {
        if ( plugin_basename( MMM_MAIL_FILE ) !== $plugin_file ) {
            return $update;
        }
        $release = self::release();
        if ( ! $release ) {
            return $update;
        }
        unset( $release['body'], $release['published'] );
        return array_merge( $release, array( 'id' => 'https://github.com/' . self::REPO, 'slug' => 'mmm-wp-mail' ) );
    }

    public static function info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || 'mmm-wp-mail' !== ( $args->slug ?? '' ) ) {
            return $result;
        }
        $release = self::release();
        if ( ! $release ) {
            return $result;
        }
        return (object) array( 'name' => 'MAO-WP-MAIL', 'slug' => 'mmm-wp-mail', 'version' => $release['version'],
            'author' => 'maomomo-eth', 'homepage' => $release['url'], 'requires' => $release['requires'],
            'requires_php' => $release['requires_php'], 'download_link' => $release['package'],
            'last_updated' => $release['published'], 'sections' => array(
                'description' => '轻量 Brevo / Resend API 和 SMTP 邮件发送插件。',
                'changelog' => nl2br( esc_html( $release['body'] ) ),
            ) );
    }

    public static function clear( $upgrader, $extra ) {
        if ( 'plugin' === ( $extra['type'] ?? '' ) && in_array( plugin_basename( MMM_MAIL_FILE ), $extra['plugins'] ?? array(), true ) ) {
            delete_site_transient( self::CACHE );
        }
    }
}
