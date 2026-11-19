<?php
/**
 * Plugin Name: MAO-WP-MAIL
 * Plugin URI: https://github.com/maomomo-eth/mmm-wp-mail
 * Description: 轻量邮件发送：Brevo API、Resend API、普通 SMTP，支持备用发送与 GitHub 后台更新。
 * Version: 1.1.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Author: maomomo-eth
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/maomomo-eth/mmm-wp-mail
 * Text Domain: mmm-wp-mail
 */

defined( 'ABSPATH' ) || exit;
define( 'MMM_MAIL_VERSION', '1.1.0' );
define( 'MMM_MAIL_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-transport.php';
require_once __DIR__ . '/includes/class-updater.php';

MMM_Mail_Transport::boot();
MMM_Mail_Updater::boot();
if ( is_admin() ) {
    require_once __DIR__ . '/includes/class-admin.php';
    MMM_Mail_Admin::boot();
}
