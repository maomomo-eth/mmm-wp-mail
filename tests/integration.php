<?php
// 在真实 WordPress 中执行；只允许项目内的隔离测试站。
$root = dirname( __DIR__ );
require $root . '/.test-wordpress/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once $root . '/includes/class-admin.php';
$count = 0;
function verify( $condition, $message ) {
    global $count;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    ++$count;
    echo '通过：' . $message . PHP_EOL;
}
function configure_test( $provider, $extra = array() ) {
    $input = array_merge( MMM_Mail_Settings::defaults(), array( 'provider' => $provider,
        'from_email' => 'sender@example.com', 'from_name' => '测试站点',
        'brevo_key' => 'test-brevo-secret', 'resend_key' => 'test-resend-secret' ), $extra );
    update_option( MMM_Mail_Settings::OPTION, MMM_Mail_Settings::sanitize( $input ), false );
}
$old = get_option( MMM_Mail_Settings::OPTION, false );
$requests = array();
$api_status = 201;
$api_error = false;
$release_data = array();
$mock = static function ( $pre, $args, $url ) use ( &$requests, &$api_status, &$api_error, &$release_data ) {
    if ( false !== strpos( $url, 'api.github.com/repos/' ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $release_data ) );
    }
    if ( in_array( $url, array( 'https://api.brevo.com/v3/smtp/email', 'https://api.resend.com/emails' ), true ) ) {
        $requests[] = array( 'url' => $url, 'args' => $args, 'payload' => json_decode( $args['body'], true ) );
        if ( $api_error ) {
            return new WP_Error( 'http_request_failed', '模拟网络超时' );
        }
        return array( 'response' => array( 'code' => $api_status ), 'body' => wp_json_encode( array( 'id' => 'test-id', 'messageId' => '<test-id@example.com>' ) ) );
    }
    return $pre;
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$success = 0;
$failure = '';
add_action( 'wp_mail_succeeded', static function () use ( &$success ) { ++$success; } );
add_action( 'wp_mail_failed', static function ( $error ) use ( &$failure ) { $failure = $error->get_error_message(); } );
$process = null;
try {
    wp_set_current_user( 1 );
    verify( is_plugin_active( 'mmm-wp-mail/mmm-wp-mail.php' ), '插件可在真实 WordPress 中激活' );
    configure_test( 'brevo' );
    $saved = MMM_Mail_Settings::get();
    verify( 'test-brevo-secret' === MMM_Mail_Settings::secret( 'brevo_key' ) && false === strpos( $saved['brevo_key'], 'test-brevo-secret' ), '凭据加密保存并正确解密' );
    $blank = $saved;
    $blank['brevo_key'] = '';
    update_option( MMM_Mail_Settings::OPTION, MMM_Mail_Settings::sanitize( $blank ) );
    verify( 'test-brevo-secret' === MMM_Mail_Settings::secret( 'brevo_key' ), '留空保留凭据' );
    $blank['clear_brevo_key'] = 1;
    update_option( MMM_Mail_Settings::OPTION, MMM_Mail_Settings::sanitize( $blank ) );
    verify( '' === MMM_Mail_Settings::secret( 'brevo_key' ), '可以清除凭据' );
    configure_test( 'brevo' );
    $fixture = $root . '/.test-wordpress/attachment.txt';
    file_put_contents( $fixture, "中文附件\n" );
    $headers = array( 'Content-Type: text/html; charset=UTF-8', 'Cc: 抄送 <cc@example.com>', 'Bcc: bcc@example.com', 'Reply-To: 回复 <reply@example.com>', 'X-Order-ID: 123' );
    foreach ( array( 'brevo', 'resend' ) as $provider ) {
        configure_test( $provider );
        verify( wp_mail( array( '接收者 <to@example.com>', 'second@example.com' ), '中文主题', '<p>中文正文</p>', $headers, array( $fixture ) ), $provider . ' HTML 邮件发送成功' );
        $last = end( $requests );
        $p = $last['payload'];
        verify( 2 === count( $p['to'] ) && isset( $p['cc'], $p['bcc'] ), $provider . ' 多收件人和抄送、密送正确' );
        verify( '中文主题' === $p['subject'] && '<p>中文正文</p>' === $p[ 'brevo' === $provider ? 'htmlContent' : 'html' ], $provider . ' 中文主题和 HTML 正文正确' );
        verify( isset( $p[ 'brevo' === $provider ? 'replyTo' : 'reply_to' ] ) && '123' === $p['headers']['X-Order-ID'], $provider . ' Reply-To 和自定义头正确' );
        $attachments = $p[ 'brevo' === $provider ? 'attachment' : 'attachments' ];
        verify( "中文附件\n" === base64_decode( $attachments[0]['content'] ), $provider . ' 附件内容正确' );
        verify( 0 === $last['args']['redirection'] && 20 === $last['args']['timeout'], $provider . ' 不重定向敏感 API 请求' );
        verify( wp_mail( 'to@example.com', '纯文本', '文本正文' ), $provider . ' 纯文本邮件发送成功' );
        $p = end( $requests )['payload'];
        verify( '文本正文' === $p[ 'brevo' === $provider ? 'textContent' : 'text' ] && ! isset( $p['cc'], $p['bcc'], $p['attachment'], $p['attachments'] ), $provider . ' 连续发送不残留收件人和附件' );
    }
    verify( 4 === $success, 'WordPress 成功钩子正常触发' );
    $api_status = 401;
    verify( ! wp_mail( 'to@example.com', '失败', '正文' ) && false !== strpos( $failure, 'HTTP 401' ), 'API 鉴权失败返回 false 并触发失败钩子' );
    $api_status = 429;
    verify( ! wp_mail( 'to@example.com', '限流', '正文' ) && false !== strpos( $failure, 'HTTP 429' ), 'API 限流错误正确反馈' );
    $api_error = true;
    verify( ! wp_mail( 'to@example.com', '超时', '正文' ) && false !== strpos( $failure, '网络请求失败' ), '网络异常正确反馈' );
    $api_error = false;
    $api_status = 201;
    $short = static function () { return false; };
    add_filter( 'pre_wp_mail', $short, 1 );
    $before = count( $requests );
    verify( ! wp_mail( 'to@example.com', '短路', '正文' ) && $before === count( $requests ), '尊重其他插件的 pre_wp_mail 短路结果' );
    remove_filter( 'pre_wp_mail', $short, 1 );
    $customize = static function ( $mail ) { $mail->addStringAttachment( 'string-content', 'string.txt' ); $mail->AltBody = '备用文本'; };
    add_action( 'phpmailer_init', $customize, 20 );
    verify( wp_mail( 'to@example.com', '兼容钩子', '<p>正文</p>', 'Content-Type: text/html' ), '兼容 phpmailer_init 追加内容' );
    $p = end( $requests )['payload'];
    verify( '备用文本' === $p['text'] && 'string-content' === base64_decode( $p['attachments'][0]['content'] ), '备用文本和内存附件正确映射' );
    remove_action( 'phpmailer_init', $customize, 20 );
    // PHPMailer 的 AltBody 不由 WordPress 清空，测试钩子须自行清理。
    $GLOBALS['phpmailer']->AltBody = '';
    $embed = static function ( $mail ) use ( $fixture ) { $mail->addEmbeddedImage( $fixture, 'test-cid' ); };
    add_action( 'phpmailer_init', $embed, 20 );
    verify( ! wp_mail( 'to@example.com', 'CID', '<p>正文</p>', 'Content-Type: text/html' ) && false !== strpos( $failure, 'CID' ), 'API 对不支持的内嵌图片明确报错' );
    remove_action( 'phpmailer_init', $embed, 20 );
    configure_test( 'brevo' );
    verify( ! wp_mail( 'to@example.com', '多回复', '正文', array( 'Reply-To: one@example.com,two@example.com' ) ) && false !== strpos( $failure, 'Reply-To' ), 'Brevo 多 Reply-To 明确报错' );
    configure_test( 'resend', array( 'force_from' => 0 ) );
    verify( wp_mail( 'to@example.com', '发件人', '正文', 'From: Custom <custom@example.com>' ), '关闭强制发件人可发送' );
    verify( false !== strpos( end( $requests )['payload']['from'], 'custom@example.com' ), '保留调用者发件人' );
    $smtp_file = $root . '/.test-wordpress/smtp.eml';
    $process = proc_open( array( PHP_BINARY, __DIR__ . '/smtp-server.php', $smtp_file ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    $address = trim( fgets( $pipes[1] ) );
    $port = (int) substr( $address, strrpos( $address, ':' ) + 1 );
    verify( $port > 0, '本地 SMTP 测试服务启动' );
    configure_test( 'smtp', array( 'smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_encryption' => '', 'smtp_auth' => 1, 'smtp_user' => 'test-user', 'smtp_password' => 'test-pass' ) );
    verify( wp_mail( 'to@example.com', 'SMTP test', 'SMTP body', '', array( $fixture ) ), '普通 SMTP 完成真实 AUTH LOGIN 和邮件传输' );
    foreach ( $pipes as $pipe ) { fclose( $pipe ); }
    verify( 0 === proc_close( $process ), 'SMTP 会话正常结束' );
    $process = null;
    $mime = file_get_contents( $smtp_file );
    verify( false !== strpos( $mime, 'SMTP body' ) && false !== strpos( $mime, 'attachment.txt' ), 'SMTP MIME 正文与附件正确' );
    configure_test( 'smtp', array( 'smtp_host' => 'localhost', 'smtp_encryption' => 'tls' ) );
    MMM_Mail_Transport::configure( $GLOBALS['phpmailer'] );
    verify( 'tls' === $GLOBALS['phpmailer']->SMTPSecure && $GLOBALS['phpmailer']->SMTPAutoTLS, 'STARTTLS 配置正确' );
    configure_test( 'smtp', array( 'smtp_host' => 'localhost', 'smtp_encryption' => 'ssl', 'smtp_port' => 465, 'smtp_auth' => 0 ) );
    MMM_Mail_Transport::configure( $GLOBALS['phpmailer'] );
    verify( 'ssl' === $GLOBALS['phpmailer']->SMTPSecure && ! $GLOBALS['phpmailer']->SMTPAuth, 'SSL/TLS 和免认证配置正确' );
    configure_test( 'resend' );
    ob_start();
    MMM_Mail_Admin::page();
    $html = ob_get_clean();
    verify( false === strpos( $html, 'test-resend-secret' ) && false !== strpos( $html, '发送测试邮件' ), '后台设置可渲染且不回显密钥' );
    $release_data = array( 'tag_name' => 'v1.0.1', 'draft' => false, 'prerelease' => false, 'body' => '<!-- mmm-wp-mail: {"requires":"6.8","requires_php":"7.4"} -->',
        'assets' => array( array( 'name' => 'mmm-wp-mail.zip', 'browser_download_url' => 'https://github.com/maomomo-eth/mmm-wp-mail/releases/download/v1.0.1/mmm-wp-mail.zip' ) ) );
    delete_site_transient( MMM_Mail_Updater::CACHE );
    $update = MMM_Mail_Updater::update( false, array(), plugin_basename( MMM_MAIL_FILE ), array() );
    verify( '1.0.1' === $update['version'] && '7.4' === $update['requires_php'] && false !== strpos( $update['package'], '/v1.0.1/' ), 'GitHub 正式 Release 识别更新包与环境要求' );
    verify( false === MMM_Mail_Updater::update( false, array(), 'other/other.php', array() ), '不影响其他 GitHub 插件更新' );
    require_once ABSPATH . 'wp-admin/includes/update.php';
    delete_site_transient( 'update_plugins' );
    wp_update_plugins();
    $updates = get_site_transient( 'update_plugins' );
    verify( '1.0.1' === $updates->response[ plugin_basename( MMM_MAIL_FILE ) ]->new_version, 'WordPress 核心更新检查实际生成更新提示' );
    $release_data['prerelease'] = true;
    delete_site_transient( MMM_Mail_Updater::CACHE );
    verify( ! MMM_Mail_Updater::release(), '忽略预发布版本' );
    $release_data['prerelease'] = false;
    $release_data['assets'][0]['browser_download_url'] = 'https://example.com/untrusted.zip';
    delete_site_transient( MMM_Mail_Updater::CACHE );
    verify( ! MMM_Mail_Updater::release(), '拒绝非本仓库的更新包地址' );
    echo '全部通过，共 ' . $count . ' 项检查；WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . PHP_EOL;
} finally {
    if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); }
    remove_filter( 'pre_http_request', $mock, 10 );
    delete_site_transient( MMM_Mail_Updater::CACHE );
    delete_site_transient( 'update_plugins' );
    if ( false === $old ) { delete_option( MMM_Mail_Settings::OPTION ); } else { update_option( MMM_Mail_Settings::OPTION, $old ); }
}
