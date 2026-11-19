#!/usr/bin/env bash
set -euo pipefail
export LANG=C.UTF-8 LC_ALL=C.UTF-8
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
mkdir -p .test-wordpress
if [[ ! -f .test-wordpress/wordpress/wp-load.php ]]; then
  curl -fsSL --retry 3 "https://wordpress.org/${WP_ARCHIVE:-latest.tar.gz}" -o .test-wordpress/wordpress.tar.gz
  tar -xzf .test-wordpress/wordpress.tar.gz -C .test-wordpress
fi
if [[ ! -f .test-wordpress/wp-cli.phar ]]; then
  curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o .test-wordpress/wp-cli.phar
fi
if [[ ! -d .test-wordpress/wordpress/wp-content/plugins/sqlite-database-integration ]]; then
  curl -fsSL --retry 3 https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip -o .test-wordpress/sqlite.zip
  unzip -q .test-wordpress/sqlite.zip -d .test-wordpress/wordpress/wp-content/plugins
fi
cp .test-wordpress/wordpress/wp-content/plugins/sqlite-database-integration/db.copy .test-wordpress/wordpress/wp-content/db.php
wp=(php "$root/.test-wordpress/wp-cli.phar" --path="$root/.test-wordpress/wordpress" --allow-root)
if [[ ! -f .test-wordpress/wordpress/wp-config.php ]]; then
  "${wp[@]}" config create --dbname=mmm_mail_test --dbuser=test --skip-check
  "${wp[@]}" config set DB_PATH "$root/.test-wordpress/database.sqlite"
  "${wp[@]}" config set DISABLE_WP_CRON true --raw
  "${wp[@]}" config set FS_METHOD direct
fi
if ! "${wp[@]}" core is-installed; then
  # 只用于本地隔离测试站，不是真实账户密码。
  "${wp[@]}" core install --url=http://127.0.0.1:8897 --title='mmm-wp-mail 测试' --admin_user=mailtest --admin_password=local-mail-test-only --admin_email=test@example.com --skip-email
fi
"${wp[@]}" option update siteurl http://127.0.0.1:8897
"${wp[@]}" option update home http://127.0.0.1:8897
if [[ ! -e .test-wordpress/wordpress/wp-content/plugins/mmm-wp-mail ]]; then
  ln -s "$root" .test-wordpress/wordpress/wp-content/plugins/mmm-wp-mail
fi
"${wp[@]}" plugin activate mmm-wp-mail
echo '测试站准备完成。'
