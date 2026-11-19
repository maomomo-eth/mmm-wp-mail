// 使用本机统一 Playwright 工具运行，不在项目中安装浏览器依赖。
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://127.0.0.1:8897/wp-login.php');
    await page.locator('#user_login').fill('mailtest');
    await page.locator('#user_pass').fill('local-mail-test-only');
    await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
    await page.goto('http://127.0.0.1:8897/wp-admin/options-general.php?page=mmm-wp-mail');
    if ((await page.locator('h1').textContent()) !== 'MAO-WP-MAIL') throw Error('插件显示名称不正确');
    await page.locator('#mmm-provider').selectOption('resend');
    await page.locator('#mmm-from_email').fill('sender@example.com');
    await page.locator('#mmm-from_name').fill('测试站点');
    await page.locator('#mmm-resend_key').fill('ui-test-secret');
    await page.locator('#mmm-backup_provider').selectOption('brevo');
    await page.locator('#mmm-backup_from_email').fill('backup@example.com');
    await page.locator('#mmm-backup_from_name').fill('备用站点');
    await page.locator('#mmm-backup_brevo_key').fill('ui-backup-secret');
    await Promise.all([page.waitForURL(/settings-updated=true/), page.getByRole('button', { name: '保存设置' }).click()]);
    if ((await page.locator('#mmm-resend_key').inputValue()) !== '') throw Error('密码输入框回显');
    if (!(await page.content()).includes('已配置')) throw Error('凭据未保存');
    if ((await page.content()).includes('ui-test-secret')) throw Error('凭据泄漏');
    if ((await page.content()).includes('ui-backup-secret')) throw Error('备用凭据泄漏');
    if ((await page.locator('#mmm-backup_provider').inputValue()) !== 'brevo') throw Error('备用配置未保存');
    if (!(await page.locator('#mmm-backup_brevo_key').isVisible())) throw Error('备用字段未显示');
    if (await page.locator('#mmm-brevo_key').isVisible()) throw Error('备用选择影响了主用字段');
    await page.getByRole('button', { name: '保存设置' }).click();
    await page.waitForLoadState('networkidle');
    if (!(await page.content()).includes('已配置')) throw Error('留空未保留凭据');
    const folder = path.resolve(__dirname, '../.test-wordpress');
    await page.screenshot({ path: path.join(folder, 'admin-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#mmm-provider').selectOption('smtp');
    if (!(await page.locator('#mmm-smtp_host').isVisible())) throw Error('SMTP 字段未显示');
    if (!(await page.locator('#mmm-backup_brevo_key').isVisible())) throw Error('主用选择影响了备用字段');
    await page.screenshot({ path: path.join(folder, 'admin-mobile.png'), fullPage: true });
    if (errors.length) throw Error(errors.join(';'));
    console.log('后台登录、保存、密钥不回显、留空保留、主备独立保存与发送方式切换、移动端检查通过');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
