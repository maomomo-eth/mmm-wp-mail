<?php
defined( 'ABSPATH' ) || exit;

/** 让 WordPress 负责地址、MIME 和钩子，仅替换最终传输。 */
final class MMM_Mail_Mailer extends WP_PHPMailer {
    public function postSend() {
        $settings = MMM_Mail_Settings::get();
        if ( in_array( $settings['provider'], array( 'brevo', 'resend' ), true ) ) {
            return MMM_Mail_Transport::send_api( $this, $settings['provider'] );
        }
        return parent::postSend();
    }
}
