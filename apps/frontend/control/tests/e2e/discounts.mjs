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
const promo = `E2E${Date.now().toString(36).toUpperCase()}`;

try {
  await page.goto(`${base}/owner/discounts`);
  await page.getByLabel('Электронная почта').fill(process.env.BW_EMAIL);
  await page.getByLabel('Пароль').fill(process.env.BW_PASSWORD);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByLabel('Код', { exact: true }).fill(await code(process.env.BW_TOTP_SECRET));
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.waitForURL(/\/owner\/discounts$/);

  await page.locator('[data-action="new-discount"]').click();
  await page.locator('[data-field="name"]').fill('Live check');
  await page.locator('[data-field="label-ru"]').fill('Проверка');
  await page.locator('[data-audience="code"]').check();
  await page.locator('[data-field="code"]').fill(promo);
  await page.locator('[data-field="percent"]').fill('20');
  await page.locator('[data-field="duration"]').selectOption('periods');
  await page.locator('[data-field="periods"]').fill('2');
  await page.locator('[data-field="usage-limit"]').fill('5');
  await page.locator('[data-plan-option="start"]').check();
  await page.locator('[data-action="create-discount"]').click();
  await page.locator('[data-field="reason"]').fill('Live check');
  await page.locator('[data-field="step-up"]').fill(await code(process.env.BW_TOTP_SECRET));
  await page.locator('[data-action="confirm"]').click();
  await page.getByRole('status').filter({ hasText: 'Скидка создана.' }).waitFor({ timeout: 60000 });
  await page.locator(`[data-discount="${promo}"]`).getByText(/20\s% · на 2 периодов/).waitFor();
  await page.screenshot({ path: `${shots}/discounts-1-created.png`, fullPage: true });
  console.log(`ok   promo code ${promo} (20 %, 2 periods, Start only, 5 uses) created in the Paddle sandbox`);

  const offerName = `Live offer ${promo}`;
  const until = await page.evaluate(() => {
    const date = new Date(Date.now() + 2 * 3600 * 1000);
    const pad = (part) => String(part).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  });
  await page.locator('[data-action="new-discount"]').click();
  await page.locator('[data-field="name"]').fill(offerName);
  await page.locator('[data-audience="automatic"]').check();
  await page.locator('[data-field="percent"]').fill('10');
  await page.locator('[data-field="duration"]').selectOption('forever');
  await page.locator('[data-field="expires-at"]').fill(until);
  await page.locator('[data-plan-option="start"]').check();
  await page.locator('[data-action="create-discount"]').click();
  await page.locator('[data-field="reason"]').fill('Live check offer');
  await page.locator('[data-field="step-up"]').fill(await code(process.env.BW_TOTP_SECRET));
  await page.locator('[data-action="confirm"]').click();
  await page.locator(`[data-discount="${offerName}"]`).getByText(/10\s% · навсегда/).waitFor({ timeout: 60000 });
  await page.screenshot({ path: `${shots}/discounts-2-offer.png`, fullPage: true });
  console.log('ok   offer for everyone (10 %, forever, Start only, ends in 2 hours) created');

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log(`PROMO ${promo}`);
  console.log('PASS');
} catch (error) {
  await page.screenshot({ path: `${shots}/failure.png`, fullPage: true }).catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
