<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// 停用保留配置；删除插件时仅清理本站配置，不跨站点删除数据。
delete_option( 'mmm_mail_settings' );
delete_site_transient( 'mmm_mail_github_release' );
