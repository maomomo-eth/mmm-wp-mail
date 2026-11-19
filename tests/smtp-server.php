<?php
// 仅监听本机的 SMTP 集成测试服务，校验 AUTH LOGIN 并收集 MIME。
$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( ! $server ) {
    exit( 1 );
}
echo stream_socket_get_name( $server, false ) . PHP_EOL;
flush();
$client = stream_socket_accept( $server, 20 );
if ( ! $client ) {
    exit( 2 );
}
stream_set_timeout( $client, 10 );
fwrite( $client, "220 local.test ESMTP\r\n" );
$data = '';
$auth = 0;
$user = '';
$authenticated = false;
while ( false !== ( $line = fgets( $client ) ) ) {
    $command = rtrim( $line, "\r\n" );
    if ( 1 === $auth ) {
        $user = base64_decode( $command );
        $auth = 2;
        fwrite( $client, "334 UGFzc3dvcmQ6\r\n" );
    } elseif ( 2 === $auth ) {
        $authenticated = 'test-user' === $user && 'test-pass' === base64_decode( $command );
        fwrite( $client, $authenticated ? "235 Authenticated\r\n" : "535 Failed\r\n" );
        $auth = 0;
    } elseif ( 0 === stripos( $command, 'EHLO' ) ) {
        fwrite( $client, "250-local.test\r\n250 AUTH LOGIN\r\n" );
    } elseif ( 'AUTH LOGIN' === $command ) {
        $auth = 1;
        fwrite( $client, "334 VXNlcm5hbWU6\r\n" );
    } elseif ( 0 === stripos( $command, 'MAIL FROM:' ) || 0 === stripos( $command, 'RCPT TO:' ) ) {
        fwrite( $client, $authenticated ? "250 OK\r\n" : "530 Authenticate first\r\n" );
    } elseif ( 'DATA' === $command ) {
        fwrite( $client, "354 Send data\r\n" );
        while ( false !== ( $line = fgets( $client ) ) && ".\r\n" !== $line ) {
            $data .= $line;
        }
        file_put_contents( $argv[1], $data );
        fwrite( $client, "250 Queued\r\n" );
    } elseif ( 'QUIT' === $command ) {
        fwrite( $client, "221 Bye\r\n" );
        break;
    } else {
        fwrite( $client, "250 OK\r\n" );
    }
}
fclose( $client );
fclose( $server );
