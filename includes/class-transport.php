<?php
defined( 'ABSPATH' ) || exit;

final class MMM_Mail_Transport {
    public static function boot() {
        add_filter( 'pre_wp_mail', array( __CLASS__, 'prepare' ), 10, 2 );
        add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 999 );
    }

    public static function prepare( $result, $atts ) {
        if ( null !== $result || ! in_array( MMM_Mail_Settings::get()['provider'], array( 'brevo', 'resend' ), true ) ) {
            return $result;
        }
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
        require_once __DIR__ . '/class-mailer.php';
        global $phpmailer;
        if ( ! $phpmailer instanceof MMM_Mail_Mailer ) {
            $phpmailer = new MMM_Mail_Mailer( true );
            $phpmailer::$validator = static function ( $email ) { return (bool) is_email( $email ); };
        }
        return $result;
    }

    public static function configure( $mail ) {
        $s = MMM_Mail_Settings::get();
        if ( ! $s['provider'] ) {
            return;
        }
        if ( $s['force_from'] && is_email( $s['from_email'] ) ) {
            $mail->setFrom( $s['from_email'], $s['from_name'], false );
        }
        $mail->SMTPDebug = 0;
        if ( 'smtp' === $s['provider'] ) {
            $mail->isSMTP();
            $mail->Host = $s['smtp_host'];
            $mail->Port = (int) $s['smtp_port'];
            $mail->SMTPSecure = $s['smtp_encryption'];
            $mail->SMTPAutoTLS = 'tls' === $s['smtp_encryption'];
            $mail->SMTPAuth = (bool) $s['smtp_auth'];
            $mail->Username = $s['smtp_user'];
            $mail->Password = MMM_Mail_Settings::secret( 'smtp_password' );
            $mail->Timeout = 20;
            $mail->SMTPKeepAlive = false;
        } else {
            $mail->isMail();
        }
    }

    private static function addresses( $addresses, $provider ) {
        return array_values( array_map( static function ( $address ) use ( $provider ) {
            if ( 'brevo' === $provider ) {
                return array( 'email' => $address[0], 'name' => $address[1] ?: $address[0] );
            }
            return $address[1] ? '"' . addcslashes( $address[1], '\\"' ) . '" <' . $address[0] . '>' : $address[0];
        }, $addresses ) );
    }

    public static function send_api( $mail, $provider ) {
        $key = MMM_Mail_Settings::secret( $provider . '_key' );
        if ( '' === $key ) {
            throw new \PHPMailer\PHPMailer\Exception( $provider . ' API Key 未设置或无法解密，请重新保存。' );
        }
        $payload = array( 'subject' => $mail->Subject );
        $sender = self::addresses( array( array( $mail->From, $mail->FromName ) ), $provider );
        $payload[ 'brevo' === $provider ? 'sender' : 'from' ] = $sender[0];
        foreach ( array( 'to' => $mail->getToAddresses(), 'cc' => $mail->getCcAddresses(), 'bcc' => $mail->getBccAddresses() ) as $type => $addresses ) {
            if ( $addresses ) {
                $payload[ $type ] = self::addresses( $addresses, $provider );
            }
        }
        $reply = self::addresses( array_values( $mail->getReplyToAddresses() ), $provider );
        if ( $reply ) {
            if ( 'brevo' === $provider && count( $reply ) > 1 ) {
                throw new \PHPMailer\PHPMailer\Exception( 'Brevo 只支持一个 Reply-To 地址，请使用 SMTP 发送此邮件。' );
            }
            $payload[ 'brevo' === $provider ? 'replyTo' : 'reply_to' ] = 'brevo' === $provider ? $reply[0] : $reply;
        }
        if ( ! empty( $payload['bcc'] ) && empty( $payload['to'] ) && 'brevo' === $provider ) {
            throw new \PHPMailer\PHPMailer\Exception( 'Brevo API 要求至少一个 To 收件人。' );
        }
        if ( 'text/html' === $mail->ContentType || 'multipart/alternative' === $mail->ContentType ) {
            $payload[ 'brevo' === $provider ? 'htmlContent' : 'html' ] = $mail->Body;
            if ( '' !== $mail->AltBody ) {
                $payload[ 'brevo' === $provider ? 'textContent' : 'text' ] = $mail->AltBody;
            }
        } elseif ( 'text/plain' === $mail->ContentType ) {
            $payload[ 'brevo' === $provider ? 'textContent' : 'text' ] = $mail->Body;
        } else {
            throw new \PHPMailer\PHPMailer\Exception( 'API 仅支持纯文本或 HTML 正文；复杂 MIME 邮件请使用 SMTP。' );
        }
        // PHPMailer 保存原始正文，API JSON 统一转换成 UTF-8。
        if ( ! in_array( strtolower( $mail->CharSet ), array( 'utf-8', 'utf8', 'us-ascii' ), true ) ) {
            if ( ! function_exists( 'mb_convert_encoding' ) ) {
                throw new \PHPMailer\PHPMailer\Exception( '非 UTF-8 邮件需要 mbstring 扩展，或改用 SMTP。' );
            }
            foreach ( array( 'subject', 'htmlContent', 'html', 'textContent', 'text' ) as $field ) {
                if ( isset( $payload[ $field ] ) ) {
                    try {
                        $payload[ $field ] = mb_convert_encoding( $payload[ $field ], 'UTF-8', $mail->CharSet );
                    } catch ( \Throwable $error ) {
                        throw new \PHPMailer\PHPMailer\Exception( '邮件字符集无效，请使用 UTF-8 或 SMTP。' );
                    }
                }
            }
        }
        $total = 0;
        foreach ( $mail->getAttachments() as $attachment ) {
            if ( 'inline' === $attachment[6] ) {
                throw new \PHPMailer\PHPMailer\Exception( '当前 API 模式不支持内嵌 CID 图片，请使用普通附件、远程图片或 SMTP。' );
            }
            $size = $attachment[5] ? strlen( $attachment[0] ) : ( is_readable( $attachment[0] ) ? filesize( $attachment[0] ) : false );
            if ( false === $size || ( $total += $size ) > 10 * 1024 * 1024 ) {
                throw new \PHPMailer\PHPMailer\Exception( '附件无法读取或总大小超过插件的 10 MB 限制。' );
            }
            $content = $attachment[5] ? $attachment[0] : file_get_contents( $attachment[0] );
            if ( false === $content ) {
                throw new \PHPMailer\PHPMailer\Exception( '附件读取失败。' );
            }
            $payload[ 'brevo' === $provider ? 'attachment' : 'attachments' ][] = array(
                'content' => base64_encode( $content ),
                'brevo' === $provider ? 'name' : 'filename' => $attachment[2],
            );
        }
        foreach ( $mail->getCustomHeaders() as $header ) {
            // 防止自定义头覆盖信封、内容类型或暴露 Bcc。
            if ( preg_match( '/\A(?:X-[a-z0-9-]+|List-Unsubscribe|List-Unsubscribe-Post)\z/i', $header[0] ) ) {
                $payload['headers'][ $header[0] ] = $header[1];
            }
        }
        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            throw new \PHPMailer\PHPMailer\Exception( '邮件包含无效字符，无法编码为 JSON。' );
        }
        $headers = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
        $headers[ 'brevo' === $provider ? 'api-key' : 'Authorization' ] = 'brevo' === $provider ? $key : 'Bearer ' . $key;
        $response = wp_remote_post( 'brevo' === $provider ? 'https://api.brevo.com/v3/smtp/email' : 'https://api.resend.com/emails', array(
            'headers' => $headers, 'body' => $json, 'timeout' => 20, 'redirection' => 0,
        ) );
        if ( is_wp_error( $response ) ) {
            throw new \PHPMailer\PHPMailer\Exception( $provider . ' 网络请求失败（' . sanitize_key( $response->get_error_code() ) . '），请检查连接；重试前确认服务商是否已接收。' );
        }
        $status = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $id = is_array( $body ) ? ( $body[ 'brevo' === $provider ? 'messageId' : 'id' ] ?? '' ) : '';
        if ( $status < 200 || $status >= 300 || ! is_string( $id ) || '' === $id ) {
            // 不记录服务商原始响应，避免密钥、收件人或邮件内容进入日志。
            throw new \PHPMailer\PHPMailer\Exception( $provider . ' 发送失败（HTTP ' . (int) $status . '）。请检查密钥、已验证发件人、额度及服务商后台。' );
        }
        return true;
    }
}
