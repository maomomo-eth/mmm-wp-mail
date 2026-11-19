# mmm-wp-mail

自用的轻量 WordPress 邮件插件，支持 Brevo API、Resend API 和普通 SMTP，直接接管 `wp_mail()`，支持 GitHub Release 后台一键更新。

运行时复用 WordPress 自带的 PHPMailer 和 HTTP API，无 Composer 依赖、外部 SDK、广告、统计或邮件内容日志。

## 安装与配置

要求 WordPress 6.8 及以上、PHP 7.4 及以上，推荐启用 OpenSSL 和 mbstring 扩展。

1. 从 [GitHub Releases](https://github.com/maomomo-eth/mmm-wp-mail/releases/latest) 下载附件 `mmm-wp-mail.zip`，不要下载页面上的源码压缩包。
2. 在 WordPress 后台选择「插件 → 安装插件 → 上传插件」，上传并启用。
3. 停用其他接管邮件发送的插件，进入「设置 → mmm-wp-mail」。
4. 选择 Brevo、Resend 或 SMTP，填写发件人及相应凭据，保存设置。
5. 发送测试邮件，并检查收件箱和垃圾邮件箱。

Brevo / Resend 需要先验证发件人或域名，并按服务商要求配置 SPF、DKIM 和 DMARC。SMTP 通常使用 STARTTLS + 587 或 SSL/TLS + 465；密码可能需要使用邮箱授权码。

「强制使用上述发件人」默认开启；关闭后保留 WordPress 或调用插件设置的发件人。首次启用尚未配置时，保持 WordPress 默认发送行为。

## 凭据

后台保存的 API Key 和 SMTP 密码使用 AES-256-GCM 加密，密钥由 WordPress 的认证盐派生，密码输入框不会回显原值。留空保留旧值，勾选清除后保存可删除对应凭据。更换认证盐后需要重新保存凭据。数据库备份和 `wp-config.php` 仍应妥善保管。

也可以在 `wp-config.php` 中配置以下常量，优先级高于后台设置：

```php
define( 'MMM_MAIL_BREVO_KEY', '你的 Brevo API Key' );
define( 'MMM_MAIL_RESEND_KEY', '你的 Resend API Key' );
define( 'MMM_MAIL_SMTP_PASSWORD', '你的 SMTP 密码或授权码' );
```

仅需定义实际使用的常量。清除后台保存的凭据不会移除常量。没有 OpenSSL 扩展时请通过常量配置凭据。

## 邮件兼容范围

- 支持纯文本、HTML、多个收件人、抄送、密送、Reply-To 和普通附件。
- 保留 WordPress 的 `wp_mail`、发件人、内容类型、字符集、`phpmailer_init`、成功与失败钩子。
- API 模式的附件原始总大小限制为 10 MB，服务商可能有额外格式、大小和配额限制。
- API 模式仅转发 `X-*`、`List-Unsubscribe` 和 `List-Unsubscribe-Post` 自定义头，不转发信封与 MIME 控制头。
- API 模式遇到 CID 内嵌图片、复杂 MIME 或 Brevo 多个 Reply-To 时明确失败，请改用 SMTP。Brevo 要求至少一个 To 收件人。
- SMTP 由 WordPress 内置 PHPMailer 处理，支持身份认证、STARTTLS、SSL/TLS 和可信内网无认证中继；主机字段支持单个域名或 IPv4 地址。
- 发送成功表示服务商接受请求，最终投递结果请查看服务商后台。本插件不保存邮件正文，不提供队列、自动重试、追踪或失败回退。
- 设置按站点保存；多站点请逐站启用、配置。删除插件会清除当前站点保存的配置，停用不会清除。

## GitHub 后台更新

安装包内的 `Update URI` 指向本仓库，更新器使用 WordPress 官方 `update_plugins_github.com` 钩子，从公开仓库最新正式 Release 查找 `mmm-wp-mail.zip`，无需 GitHub Token。

WordPress 检查更新后会在插件列表显示「立即更新」，也可使用后台提供的自动更新开关。设置页的「检查更新」可清理插件的 Release 缓存并刷新更新信息。正常结果缓存 6 小时，网络异常或未发布结果缓存 10 分钟。站点需要能访问 GitHub API 和 Release 下载地址。

共享出口可能触发 GitHub 匿名 API 限流。遇到该情况，可以在 `wp-config.php` 定义 `MMM_MAIL_GITHUB_TOKEN` 为具有公开仓库读取权限的 GitHub Token。Token 仅发送到本仓库的 GitHub API 请求，不附加到安装包下载请求；仓库仍需公开。

发布新版本：

1. 同步修改主插件文件的版本头、`MMM_MAIL_VERSION` 和 `readme.txt` 的 Stable tag，更新变更说明。
2. 提交并推送代码，再推送匹配的标签，例如 `v1.0.1`。
3. GitHub Actions 自动运行集成测试、生成固定目录结构的 ZIP 并创建正式 Release。
4. 已安装站点在下一次更新检查时获得新版本。草稿、预发布和仅有源码包的 Release 不用于更新。

仓库需要启用 GitHub Actions。发布工作流只需要仓库自带的 `GITHUB_TOKEN`，不需要额外配置 Secret。后续版本的最低环境要求由发布说明中的机器可读信息提供给 WordPress。

## 本地验证与构建

在项目目录中执行，测试环境需 PHP CLI、PDO SQLite、OpenSSL、mbstring、Zip、curl 和 unzip：

```bash
bash scripts/setup-test.sh
php tests/integration.php
php scripts/build.php
npx --yes markdownlint-cli2 --config .markdownlint.json README.md
npx --yes @lint-md/cli --config .lintmdrc README.md
```

测试脚本会下载 WordPress、WP-CLI 和官方 SQLite 集成插件到项目内 `.test-wordpress/`，使用隔离测试数据库；固定账户仅用于本地测试，不要将测试站对公网开放。API 请求通过 WordPress HTTP 钩子模拟，SMTP 使用本地测试服务，不发送外部真实邮件。

构建产物位于 `dist/mmm-wp-mail.zip`，只包含插件运行文件、许可证和插件说明，测试环境与开发文件不会进入安装包。

## 接口参考

- [Brevo 事务邮件 API](https://developers.brevo.com/reference/send-transac-email)
- [Resend 邮件 API](https://resend.com/docs/api-reference/emails/send-email)
- [WordPress 更新钩子](https://developer.wordpress.org/reference/hooks/update_plugins_hostname/)

## 许可

GPL-2.0-or-later，详见 LICENSE。
