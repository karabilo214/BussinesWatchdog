import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.BW_CONTROL_URL;
const shots = process.env.BW_SHOTS ?? '/tmp';

function totp(secret, offset = 0) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...secret].map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + offset));
  const hash = createHmac('sha1', key).update(counter).digest();
  const at = hash[19] & 0x0f;

  return String((hash.readUInt32BE(at) & 0x7fffffff) % 1000000).padStart(6, '0');
}

const browser = await chromium.launch();
const context = await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 860 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

try {
  await page.goto(`${base}/owner/plans`);
  await page.waitForURL(/\/owner\/login\?redirect=/);
  console.log('ok   a visitor without a staff session is sent to the sign-in page');

  await page.getByLabel('Электронная почта').fill(process.env.BW_EMAIL);
  await page.getByLabel('Пароль').fill('wrong-password-0000');
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByRole('alert').filter({ hasText: 'Неверная почта или пароль.' }).waitFor();
  console.log('ok   a wrong password is refused');

  await page.getByLabel('Пароль').fill(process.env.BW_PASSWORD);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByLabel('Код', { exact: true }).fill(totp(process.env.BW_TOTP_SECRET, 1));
  await page.screenshot({ path: `${shots}/1-code.png` });
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.waitForURL(/\/owner\/plans$/);
  await page.getByRole('heading', { name: 'Тарифы и скидки' }).waitFor();
  await page.locator('[data-mode]').filter({ hasText: 'TEST' }).waitFor();
  await page.screenshot({ path: `${shots}/2-panel.png` });
  console.log('ok   password and authenticator code open the panel at the requested section');

  const cookies = (await context.cookies()).map((cookie) => cookie.name);
  if (!cookies.includes('bw-platform-session') || !cookies.includes('BW-PLATFORM-XSRF') || cookies.includes('XSRF-TOKEN')) {
    throw new Error(`unexpected cookies: ${cookies.join(', ')}`);
  }
  console.log('ok   the panel uses its own session and CSRF cookies');

  await page.reload();
  await page.getByRole('heading', { name: 'Тарифы и скидки' }).waitFor();
  await page.getByRole('button', { name: 'Выйти' }).click();
  await page.waitForURL(/\/owner\/login/);
  await page.goto(`${base}/owner`);
  await page.waitForURL(/\/owner\/login/);
  console.log('ok   sign-out ends the staff session');

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log('PASS');
} catch (error) {
  await page.screenshot({ path: `${shots}/failure.png`, fullPage: true }).catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
