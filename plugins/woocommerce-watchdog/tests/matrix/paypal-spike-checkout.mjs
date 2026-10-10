import { chromium } from 'playwright';

const origin = process.env.BW_ORIGIN;
const browser = await chromium.launch();
const context = await browser.newContext({ locale: 'en-US', viewport: { width: 1280, height: 1000 } });
const page = await context.newPage();
page.on('response', async (r) => { if (r.status() >= 500) console.log('HTTP', r.status(), r.request().method(), new URL(r.url()).pathname + new URL(r.url()).search.slice(0, 80), (await r.text().catch(() => '')).replace(/\s+/g, ' ').slice(0, 300)); });
const shot = (target, name) => target.screenshot({ path: `/out/${name}.png`, fullPage: true }).catch(() => undefined);

let popupPage = null;

async function approve(popup) {
  popupPage = popup;
  await popup.waitForLoadState('domcontentloaded');
  const email = popup.locator('#email');
  await email.waitFor({ timeout: 60000 });
  await email.fill(process.env.BUYER_EMAIL);
  await popup.getByRole('button', { name: 'Next', exact: true }).click();
  const password = popup.getByLabel('Password', { exact: true });
  await password.waitFor({ state: 'visible', timeout: 60000 });
  await popup.waitForTimeout(1500);
  await password.click();
  await password.pressSequentially(process.env.BUYER_PASSWORD, { delay: 30 });
  await popup.getByRole('button', { name: /^Log ?In$/i }).first().click();
  const submit = popup.getByRole('button', { name: /^(Pay|Pay Now|Complete Purchase|Continue to Review Order|Continue)$/ }).first();
  await submit.waitFor({ timeout: 90000 });
  await popup.waitForTimeout(2000);
  await submit.click();
  await popup.waitForEvent('close', { timeout: 90000 });
}

try {
  await page.goto(`${origin}/?add-to-cart=${process.env.BW_PRODUCT_ID}`, { waitUntil: 'domcontentloaded' });
  await page.goto(`${origin}/?page_id=${process.env.BW_CHECKOUT_PAGE_ID}`, { waitUntil: 'networkidle', timeout: 60000 });
  const fields = { billing_first_name: 'Spike', billing_last_name: 'Tester', billing_address_1: '1 Test Street', billing_city: 'San Jose', billing_postcode: '95131', billing_phone: '4085550100', billing_email: 'spike@example.test' };
  await page.locator('#billing_country').selectOption('US').catch(() => undefined);
  await page.waitForTimeout(1500);
  for (const [id, value] of Object.entries(fields)) await page.locator(`#${id}`).fill(value).catch(() => undefined);
  await page.locator('#billing_state').selectOption('CA').catch(() => undefined);
  await page.locator('label[for="payment_method_ppcp-gateway"]').click();
  await page.waitForTimeout(4000);
  const button = page.locator('#ppc-button-ppcp-gateway-v6 paypal-button, #ppc-button-ppcp-gateway paypal-button').first();
  await button.waitFor({ timeout: 60000 });
  const [popup] = await Promise.all([page.waitForEvent('popup', { timeout: 60000 }), button.click()]);
  await approve(popup);
  await page.waitForURL(/order-received/, { timeout: 120000 });
  const order = new URL(page.url()).pathname.match(/order-received\/(\d+)/)?.[1] ?? new URL(page.url()).searchParams.get('order-received');
  console.log(`BW_PAYPAL_ORDER=${order}`);
} catch (error) {
  await shot(page, 'checkout-failure');
  if (popupPage && !popupPage.isClosed()) {
    await shot(popupPage, 'popup-failure');
    console.log('POPUP', new URL(popupPage.url()).pathname);
  }
  console.log('FAILED', String(error.message).split('\n').slice(0, 12).join(' / '));
  process.exitCode = 1;
} finally {
  await browser.close();
}
