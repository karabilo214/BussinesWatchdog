import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.BW_CONTROL_URL;
const site = process.env.BW_SITE_URL;
const shots = process.env.BW_SHOTS ?? '/tmp';

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
const context = await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

async function shot(target, name) {
  await target.screenshot({ path: `${shots}/overview-${name}.png`, fullPage: true });
}

try {
  await page.goto(`${base}/owner`);
  await page.getByLabel('Электронная почта').fill(process.env.BW_EMAIL);
  await page.getByLabel('Пароль').fill(process.env.BW_PASSWORD);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByLabel('Код', { exact: true }).fill(code(process.env.BW_TOTP_SECRET));
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.locator('[data-section="customers"]').waitFor();
  await page.locator('[data-synced], [data-incomplete]').first().waitFor();
  await shot(page, '1-live');
  console.log(`ok   overview LIVE: paying ${(await page.locator('[data-kpi="paying"]').innerText()).replace(/\s+/g, ' ')}`);

  await page.locator('[data-mode="test"]').click();
  await page.waitForResponse((response) => response.url().includes('/platform-api/v1/overview') && response.url().includes('mode=test'));
  await page.locator('[data-section="mrr"]').waitFor();
  await shot(page, '2-test');
  console.log(`ok   overview TEST: MRR cards ${await page.locator('[data-mrr]').count()}, money rows ${await page.locator('[data-money]').count()}`);

  await page.locator('[data-kpi="paying"]').click();
  await page.waitForURL(/\/owner\/subscriptions\?status=active/);
  console.log('ok   drill-down opens the subscriptions list filtered by status');

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log('PASS');
} catch (error) {
  await shot(page, 'failure').catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
