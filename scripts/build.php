<?php
// 只打包运行文件，固定顶层目录，避免更新后出现重复插件。
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/mmm-wp-mail.php' );
preg_match( '/^ \* Version: (.+)$/m', $source, $version );
preg_match( '/^ \* Requires at least: (.+)$/m', $source, $wp );
preg_match( '/^ \* Requires PHP: (.+)$/m', $source, $php );
if ( empty( $version[1] ) || empty( $wp[1] ) || empty( $php[1] ) ) {
    throw new RuntimeException( '插件头信息不完整。' );
}
preg_match( "/define\( 'MMM_MAIL_VERSION', '([^']+)' \)/", $source, $constant );
$readme = file_get_contents( $root . '/readme.txt' );
preg_match( '/^Stable tag: (.+)$/m', $readme, $stable );
if ( ( $constant[1] ?? '' ) !== $version[1] || ( $stable[1] ?? '' ) !== $version[1] ) {
    throw new RuntimeException( '版本头、版本常量和 Stable tag 必须一致。' );
}
if ( isset( $argv[1] ) && 'v' . $version[1] !== $argv[1] ) {
    throw new RuntimeException( '发布标签必须与插件版本一致。' );
}
if ( ! is_dir( $root . '/dist' ) ) {
    mkdir( $root . '/dist', 0755, true );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $root . '/dist/mmm-wp-mail.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
    throw new RuntimeException( '无法创建 ZIP。' );
}
$files = array_merge( array( 'mmm-wp-mail.php', 'uninstall.php', 'readme.txt', 'LICENSE' ), array_map( static function ( $file ) use ( $root ) { return substr( $file, strlen( $root ) + 1 ); }, glob( $root . '/includes/*.php' ) ) );
foreach ( $files as $file ) {
    $zip->addFile( $root . '/' . $file, 'mmm-wp-mail/' . $file );
}
$zip->close();
$meta = '<!-- mmm-wp-mail: ' . json_encode( array( 'requires' => trim( $wp[1] ), 'requires_php' => trim( $php[1] ) ) ) . ' -->';
file_put_contents( $root . '/dist/release-notes.txt', "轻量 WordPress 邮件插件：Brevo API、Resend API、SMTP。\n\n安装附件 mmm-wp-mail.zip，后续可在 WordPress 后台一键更新。\n\n" . $meta . "\n" );
echo '已生成 dist/mmm-wp-mail.zip，版本 ' . $version[1] . PHP_EOL;
