import { chromium } from 'playwright';

const base = process.env.BW_APP_URL;
const shots = process.env.BW_SMOKE_SCREENSHOTS ?? '/tmp';
const browser = await chromium.launch();
const page = await (await browser.newContext({ locale: 'ru-RU', timezoneId: 'Europe/Berlin', viewport: { width: 1280, height: 900 } })).newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

async function shot(name) {
  await page.screenshot({ path: `${shots}/billing-${name}.png`, fullPage: true });
}

try {
  await page.goto(`${base}/app/login`);
  await page.getByLabel('Электронная почта').fill(process.env.BW_SMOKE_EMAIL);
  await page.getByLabel('Пароль').fill(process.env.BW_SMOKE_PASSWORD);
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.waitForURL(/\/app\/overview$/);

  await page.goto(`${base}/app/settings/billing`);
  await page.locator('[data-plan="start"]').waitFor();
  await page.locator('[data-extra="start"]').fill('1');
  await page.locator('[data-plan="start"]').getByText(/Итого: 28,00\s€/).waitFor();
  await shot('1-plans');
  console.log('ok   plans shown, Start with one extra store totals 28,00 €');

  if (process.env.BW_PROMO) {
    await page.locator('[data-field="promo"]').fill(process.env.BW_PROMO.toLowerCase());
    await page.locator('[data-action="apply-promo"]').click();
    await page.locator('[data-promo="applied"]').waitFor();
    const expected = process.env.BW_EXPECT_AFTER ?? '22,40';
    await page.locator('[data-plan="start"] [data-total="discounted"]').getByText(new RegExp(`28,00\\s€\\s+${expected}\\s€`)).waitFor();
    await shot('1b-promo');
    console.log(`ok   discounts applied one after another: 28,00 € → ${expected} € for the first payment`);
  }

  await page.locator('[data-action="buy-start"]').click();
  const frame = page.frameLocator('iframe[name="paddle_frame"], iframe.paddle-frame').first();
  await frame.locator('body').waitFor({ timeout: 30000 });
  await page.waitForTimeout(5000);
  await shot('2-paddle');

  const email = frame.locator('input[type="email"], input[name="email"]').first();
  if (await email.isVisible().catch(() => false)) {
    await email.fill('buyer@example.com');
    const country = frame.locator('select[name="countryCode"], select').first();
    if (await country.isVisible().catch(() => false)) await country.selectOption('DE');
    const postcode = frame.locator('input[name="postalCode"], input[name="postcode"]').first();
    if (await postcode.isVisible().catch(() => false)) await postcode.fill('10115');
    await frame.locator('button[type="submit"]').last().click();
    await page.waitForTimeout(5000);
  }
  await shot('3-card');

  const fill = async (selector, value) => {
    const field = frame.locator(selector).first();
    await field.waitFor({ timeout: 30000 });
    await field.fill(value);
  };
  await fill('input[name="cardNumber"], #cardNumber', '4242424242424242');
  await fill('input[name="cardHolder"], #cardHolder', 'Test Buyer');
  await fill('input[name="expiry"], #expiry', '12/30');
  await fill('input[name="cvv"], #cvv', '100');
  await frame.locator('button[type="submit"]').last().click();
  console.log('ok   paid with the Paddle test card');

  await page.locator('[data-checkout="confirming"], [data-checkout="confirmed"]').first().waitFor({ timeout: 60000 });
  console.log('ok   page waits for the backend confirmation');
  await page.locator('[data-checkout="confirmed"]').waitFor({ timeout: 240000 });
  await page.keyboard.press('Escape');
  await page.reload();
  await page.locator('[data-panel="subscription"]').getByText('Оплачена').waitFor();
  await page.locator('[data-plan="start"]').getByText('Текущий').waitFor();
  await shot('4-confirmed');
  console.log('ok   payment confirmed by the backend, subscription shown as paid');

  if (process.env.BW_CHANGE) {
    await page.locator('[data-extra="pro"]').fill('0');
    await page.locator('[data-action="change-pro"]').click();
    const panel = page.locator('[data-panel="plan-change"]');
    await panel.locator('[data-preview="due-now"]').waitFor({ timeout: 30000 });
    console.log(`ok   upgrade preview: charged now ${(await panel.locator('[data-preview="due-now"]').innerText()).trim()}, next payment ${(await panel.locator('[data-preview="next"]').innerText()).trim()}`);
    await shot('5-upgrade-preview');
    await panel.locator('[data-action="confirm-change"]').click();
    await page.locator('[data-notice="change-applied"]').waitFor({ timeout: 60000 });
    await page.locator('[data-plan="pro"]').getByText('Текущий').waitFor();
    await shot('6-upgraded');
    console.log('ok   upgrade paid at Paddle and Pro read back as the current plan');

    await page.locator('[data-extra="start"]').fill('0');
    await page.locator('[data-action="change-start"]').click();
    await panel.locator('[data-preview="due-now"]').waitFor({ timeout: 30000 });
    await panel.getByText('Сейчас ничего не списывается').waitFor();
    await panel.locator('[data-action="confirm-change"]').click();
    await page.locator('[data-notice="change-scheduled"]').waitFor({ timeout: 60000 });
    await page.locator('[data-scheduled="change"]').getByText(/сменится на «Start»|сменится на «Старт»/).waitFor();
    await page.locator('[data-plan="pro"]').getByText('Текущий').waitFor();
    await shot('7-downgrade-scheduled');
    console.log('ok   cheaper plan scheduled for the next period, Pro stays until then');

    await page.locator('[data-action="withdraw-change"]').click();
    await page.locator('[data-notice="change-withdrawn"]').waitFor({ timeout: 60000 });
    if (await page.locator('[data-scheduled="change"]').count()) throw new Error('scheduled change still shown');
    console.log('ok   scheduled change withdrawn');

    await page.locator('[data-action="cancel-subscription"]').click();
    await page.locator('[data-field="cancel-reason"]').fill('live check');
    await page.locator('[data-action="confirm-cancel"]').click();
    await page.locator('[data-scheduled="cancel"]').waitFor({ timeout: 60000 });
    await shot('8-cancel-scheduled');
    console.log('ok   renewal cancelled at the period end');

    await page.locator('[data-action="resume"]').click();
    await page.locator('[data-notice="change-resumed"]').waitFor({ timeout: 60000 });
    if (await page.locator('[data-scheduled="cancel"]').count()) throw new Error('cancellation still shown');
    await shot('9-resumed');
    console.log('ok   subscription resumed');
  }

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log('PASS');
} catch (error) {
  await shot('failure').catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
