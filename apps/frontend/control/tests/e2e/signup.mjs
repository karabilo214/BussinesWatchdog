import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.BW_CONTROL_URL;
const site = process.env.BW_SITE_URL;
const shots = process.env.BW_SHOTS ?? '/tmp';
const stamp = Date.now().toString(36);
const email = `signup-${stamp}@example.test`;

function code(secret) {
  const step = Math.floor(Date.now() / 30000);
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...secret].map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hash = createHmac('sha1', key).update(counter).digest();
  const at = hash[19] & 0x0f;

  return String((hash.readUInt32BE(at) & 0x7fffffff) % 1000000).padStart(6, '0');
}

const browser = await chromium.launch();
const page = await (await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } })).newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
const created = [];

async function shot(target, name) {
  await target.screenshot({ path: `${shots}/signup-${name}.png`, fullPage: true });
}

async function documentPage(slug, title, policy) {
  await page.goto(`${base}/owner/site`);
  await page.locator('[data-field="slug"]').fill(slug);
  await page.locator('[data-field="menu"]').first().selectOption('footer');
  await page.locator('[data-action="create"]').click();
  await page.waitForURL(/\/owner\/site\/pages\//);
  created.push(page.url());
  await page.locator('[data-field="title"]').fill(title);
  await page.locator('[data-field="body"]').fill(`<p>${title}: текст документа.</p>`);
  await page.locator('[data-action="save"]').click();
  await page.locator('[data-notice]').getByText('Черновик сохранён.').waitFor();
  await page.locator('[data-action="publish"]').click();
  await page.locator('[data-field="reason"]').fill('Первая редакция');
  await page.locator('[data-action="confirm"]').click();
  await page.locator('[data-notice]').getByText('Опубликовано.').waitFor();
  await page.locator('[data-field="policy"]').selectOption(policy);
  await page.locator('[data-action="save-settings"]').click();
  await page.locator('[data-notice]').getByText('Настройки сохранены.').waitFor();
}

try {
  await page.goto(`${base}/owner/site`);
  await page.getByLabel('Электронная почта').fill(process.env.BW_EMAIL);
  await page.getByLabel('Пароль').fill(process.env.BW_PASSWORD);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  const step = Math.floor(Date.now() / 30000);
  while (Math.floor(Date.now() / 30000) === step) await new Promise((resolve) => setTimeout(resolve, 1000));
  await page.getByLabel('Код', { exact: true }).fill(code(process.env.BW_TOTP_SECRET));
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.waitForURL((url) => url.pathname === '/owner/site');

  await documentPage(`terms-${stamp}`, 'Условия использования', 'service_terms');
  await documentPage(`privacy-${stamp}`, 'Политика конфиденциальности', 'privacy_notice');
  await page.goto(`${base}/owner/site`);
  await page.locator(`[data-page="terms-${stamp}"] [data-policy="service_terms"]`).waitFor();
  await shot(page, '1-pages');
  console.log('ok   two pages published and marked as terms of service and privacy notice in the owner panel');

  const visitor = await (await browser.newContext({ locale: 'ru-RU', viewport: { width: 390, height: 844 }, javaScriptEnabled: false })).newPage();
  await visitor.goto(`${site}/ru/pricing`);
  await visitor.goto(`${site}/ru/signup?plan=start`);
  await visitor.locator(`[data-documents] a[href="/ru/terms-${stamp}"]`).waitFor();
  await visitor.locator(`[data-documents] a[href="/ru/privacy-${stamp}"]`).waitFor();
  await visitor.getByLabel('Ваше имя').fill('Live Signup');
  await visitor.getByLabel('Название организации или магазина').fill(`Live Shop ${stamp}`);
  await visitor.getByLabel('E-mail').fill(email);
  await visitor.getByLabel('Пароль', { exact: true }).fill('live-signup-password-1');
  await visitor.getByLabel('Пароль ещё раз').fill('live-signup-password-1');
  await visitor.getByRole('button', { name: 'Создать аккаунт' }).click();
  await visitor.locator('.site-errors').getByText('Нужно принять условия').waitFor();
  await shot(visitor, '2-consent-required');
  console.log('ok   sign-up page served without JavaScript, links the marked documents, refuses without consent');

  await visitor.getByLabel('Пароль', { exact: true }).fill('live-signup-password-1');
  await visitor.getByLabel('Пароль ещё раз').fill('live-signup-password-1');
  await visitor.locator('[data-documents] input[type="checkbox"]').check();
  await Promise.all([visitor.waitForURL(/\/app\/settings\/billing\?plan=start$/), visitor.getByRole('button', { name: 'Создать аккаунт' }).click()]);
  const again = await visitor.goto(`${site}/ru/signup`);
  if (!again?.url().endsWith('/app/settings/billing')) throw new Error(`not signed in after sign-up (${again?.url()})`);
  console.log('ok   account and organisation created, signed in, sent to the chosen plan; the sign-up page now sends the signed-in person to the account');

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log(`PASS ${email}`);
} catch (error) {
  await shot(page, 'failure').catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n').slice(0, 4).join(' | ')} @ ${page.url()}`);
  process.exitCode = 1;
} finally {
  for (const url of created) {
    try {
      await page.goto(url);
      await page.locator('[data-action="archive"]').click();
      await page.locator('[data-field="reason"]').fill('live check cleanup');
      await page.locator('[data-action="confirm"]').click();
      await page.waitForURL(/\/owner\/site$/);
    } catch {
      console.log(`cleanup failed for ${url}`);
    }
  }
  await browser.close();
}
