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
const planCode = `e2e-${Date.now().toString(36)}`;

try {
  await page.goto(`${base}/owner/plans`);
  await page.getByLabel('Электронная почта').fill(process.env.BW_EMAIL);
  await page.getByLabel('Пароль').fill(process.env.BW_PASSWORD);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByLabel('Код', { exact: true }).fill(await code(process.env.BW_TOTP_SECRET));
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.waitForURL(/\/owner\/plans$/);

  await page.locator('[data-action="new-plan"]').click();
  await page.locator('[data-field="code"]').fill(planCode);
  await page.locator('[data-field="name-ru"]').fill('Агентство');
  await page.locator('[data-field="name-en"]').fill('Agency');
  await page.getByRole('button', { name: 'Создать черновик' }).click();
  await page.waitForURL(/\/owner\/plans\/versions\//);
  console.log('ok   a new plan opens as a draft');

  await page.locator('[data-field="name-de"]').fill('Agentur');
  await page.locator('[data-field="trial"]').fill('21');
  await page.locator('[data-feature="checkout_monitoring"]').check();
  await page.locator('[data-feature="money_reconciliation"]').check();
  const stores = page.locator('[data-limit="max_active_stores"]');
  await stores.getByRole('checkbox').uncheck();
  await stores.locator('input[inputmode="numeric"]').fill('5');
  const extra = page.locator('[data-limit="max_extra_stores"]');
  await extra.getByRole('checkbox').uncheck();
  await extra.locator('input[inputmode="numeric"]').fill('20');
  await page.locator('[data-action="save"]').click();
  await page.getByRole('status').filter({ hasText: 'Сохранено.' }).waitFor();
  console.log('ok   names, trial, features and limits are saved');

  await page.locator('[data-field="amount"]').fill('45,50');
  await page.locator('[data-field="count"]').fill('2');
  await page.locator('[data-field="interval"]').selectOption('week');
  await page.locator('[data-form="price"]').getByText('каждые 2 недели').waitFor();
  await page.locator('[data-action="add-price"]').click();
  await page.locator('[data-price="base"]').getByText(/45,50\s€ каждые 2 недели/).waitFor();
  await page.locator('[data-field="kind"]').selectOption('extra_store');
  await page.locator('[data-field="amount"]').fill('5');
  await page.locator('[data-action="add-price"]').click();
  await page.locator('[data-price="extra_store"]').getByText(/5,00\s€ каждые 2 недели/).waitFor();
  await page.screenshot({ path: `${shots}/plans-1-draft.png`, fullPage: true });
  console.log('ok   base and extra-store prices billed every 2 weeks');

  await page.locator('[data-action="publish"]').click();
  await page.locator('[data-field="reason"]').fill('Live check');
  await page.locator('[data-field="step-up"]').fill('000000');
  await page.locator('[data-action="confirm"]').click();
  await page.getByRole('alert').filter({ hasText: 'Код подтверждения не подошёл.' }).waitFor();
  console.log('ok   a wrong confirmation code does not publish');

  await page.locator('[data-field="step-up"]').fill(await code(process.env.BW_TOTP_SECRET));
  await page.locator('[data-action="confirm"]').click();
  await page.getByText('Продаётся').first().waitFor({ timeout: 60000 });
  await page.locator('[data-price="base"]').getByText('создана в Paddle').waitFor();
  await page.screenshot({ path: `${shots}/plans-2-published.png`, fullPage: true });
  console.log('ok   publishing created the product and prices in the Paddle sandbox');

  await page.locator('[data-action="retire"]').click();
  await page.locator('[data-field="reason"]').fill('Live check done');
  await page.locator('[data-field="step-up"]').fill(await code(process.env.BW_TOTP_SECRET));
  await page.locator('[data-action="confirm"]').click();
  await page.getByText('Версия снята с продажи.').waitFor();
  console.log(`ok   the plan ${planCode} is retired again`);

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log(`PLAN ${planCode}`);
  console.log('PASS');
} catch (error) {
  await page.screenshot({ path: `${shots}/failure.png`, fullPage: true }).catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
