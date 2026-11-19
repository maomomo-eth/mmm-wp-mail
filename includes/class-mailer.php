<?php
defined( 'ABSPATH' ) || exit;

/** 让 WordPress 负责地址、MIME 和钩子，仅替换最终传输。 */
final class MMM_Mail_Mailer extends WP_PHPMailer {
    private $profile_prefix = '';

    public function send() {
        $settings = MMM_Mail_Settings::get();
        if ( ! $settings['provider'] ) {
            return parent::send();
        }
        // 在 preSend 改写正文、编码及 MIME 状态之前保存副本。
        $backup = $settings['backup_provider'] ? clone $this : null;
        try {
            if ( parent::send() ) {
                MMM_Mail_Transport::$last_delivery = array( 'backup' => false, 'provider' => $settings['provider'] );
                return true;
            }
            $primary_error = $this->ErrorInfo;
        } catch ( \PHPMailer\PHPMailer\Exception $error ) {
            $primary_error = $error->getMessage();
        }
        $this->smtpClose();
        if ( null === $backup ) {
            return $this->fail( $primary_error );
        }
        // 副本不共享 SMTP 会话，备用只发送一次，不递归调用 wp_mail。
        $backup->smtp = null;
        $backup->profile_prefix = 'backup_';
        try {
            MMM_Mail_Transport::configure_profile( $backup, 'backup_' );
            if ( $backup->send_once() ) {
                $this->ErrorInfo = '';
                MMM_Mail_Transport::$last_delivery = array( 'backup' => true, 'provider' => $settings['backup_provider'] );
                return true;
            }
            $backup_error = $backup->ErrorInfo;
        } catch ( \PHPMailer\PHPMailer\Exception $error ) {
            $backup_error = $error->getMessage();
        } finally {
            $backup->smtpClose();
        }
        return $this->fail( '主用通道失败：' . $primary_error . '；备用通道失败：' . $backup_error );
    }

    private function send_once() {
        return parent::send();
    }

    private function fail( $message ) {
        $this->setError( $message );
        if ( $this->exceptions ) {
            throw new \PHPMailer\PHPMailer\Exception( $message );
        }
        return false;
    }

    public function postSend() {
        $settings = MMM_Mail_Settings::profile( $this->profile_prefix );
        if ( in_array( $settings['provider'], array( 'brevo', 'resend' ), true ) ) {
            return MMM_Mail_Transport::send_api( $this, $settings['provider'], $this->profile_prefix );
        }
        return parent::postSend();
    }
}
