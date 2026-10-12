import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.BW_CONTROL_URL;
const shots = process.env.BW_SHOTS ?? '/tmp';
let lastStep = 0;

async function code(secret) {
  let step = Math.floor(Date.now() / 30000);

  while (step <= lastStep) {
    await new Promise((resolve) => setTimeout(resolve, 1000));
    step = Math.floor(Date.now() / 30000);
  }

  lastStep = step;
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
function local(days) {
  const date = new Date(Date.now() + days * 86_400_000);
  const pad = (value) => String(value).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:00`;
}

async function shot(name) {
  await page.screenshot({ path: `${shots}/tenants-${name}.png`, fullPage: true });
}

async function act(action, reason, fill = async () => undefined) {
  await page.locator(`[data-panel="actions"] [data-action="${action}"]`).click();
  await fill();
  await page.locator('[data-action="preview"]').click();
  await page.locator('[data-preview="action"]').waitFor();
  await page.locator('[data-field="reason"]').fill(reason);
  await page.locator('[data-field="step-up"]').fill(await code(process.env.BW_TOTP_SECRET));
  await page.locator('[data-action="confirm"]').click();
  await page.locator(`[data-done="${action}"]`).waitFor();
}

const tenant = process.env.BW_TENANT;

try {
  await page.goto(`${base}/owner/tenants`);
  await page.getByLabel('Электронная почта').fill(process.env.BW_EMAIL);
  await page.getByLabel('Пароль').fill(process.env.BW_PASSWORD);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByLabel('Код', { exact: true }).fill(await code(process.env.BW_TOTP_SECRET));
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.waitForURL(/\/owner\/tenants$/);

  await page.locator('[data-field="search"]').fill(tenant);
  await page.locator('[data-field="subscription"]').selectOption('trial');
  await page.locator('[data-action="filter"]').click();
  const row = page.locator(`[data-tenant="${tenant}"]`);
  await row.waitFor();
  if (!new URL(page.url()).search.includes('subscription=trial') || page.url().includes('Kaffee')) throw new Error('filters must be in the address, the search must not');
  await row.getByText('Пробный период').first().waitFor();
  await shot('1-list');
  console.log('ok   organisation found by name among trials; filter kept in the address, search not');

  await row.getByRole('link', { name: tenant }).click();
  await page.locator('[data-panel="actions"]').waitFor();
  await page.locator('[data-tab="stores"]').click();
  await page.locator('[data-section="stores"]').getByText('Live Shop').waitFor();
  await page.locator('[data-tab="overview"]').click();
  console.log('ok   card opened, stores shown as a summary');

  await act('extend_trial', 'Customer asked for more time', async () => page.locator('[data-field="until"]').fill(local(10)));
  await page.locator('[data-tab="subscription"]').click();
  await page.locator('[data-trial-ends]').waitFor();
  console.log(`ok   trial extended, now until ${(await page.locator('[data-trial-ends]').innerText()).trim()}`);
  await page.locator('[data-tab="overview"]').click();

  await act('suspend', 'Live check suspend');
  await page.locator('[data-badge="suspended"]').waitFor();
  await page.locator('[data-badge="access"]').getByText('Пауза: вручную').waitFor();
  await shot('2-suspended');
  console.log('ok   service suspended, access paused manually');

  await act('resume', 'Live check resume');
  if (await page.locator('[data-badge="suspended"]').count()) throw new Error('still suspended');
  console.log('ok   service resumed');

  await page.locator('[data-tab="audit"]').click();
  for (const action of ['tenant.extend_trial', 'tenant.suspend', 'tenant.resume']) {
    await page.locator(`[data-audit="${action}"]`).first().waitFor();
  }
  await shot('3-audit');
  console.log('ok   every action is in the organisation audit');

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log('PASS');
} catch (error) {
  await shot('failure').catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
