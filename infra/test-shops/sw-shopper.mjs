// A real shopper for the Shopware test store (docs/ops/test-shops.md): buys one product as a guest and pays with the
// chosen method (prepayment, invoice, cash on delivery, or PayPal Sandbox with the sandbox buyer from BUYER_EMAIL /
// BUYER_PASSWORD, through the PayPal redirect). Prints one JSON line with the order number.
// Usage (inside the browser-worker image): BW_SHOP=https://shopware-test… BW_PAY=prepayment|invoice|cash|paypal node sw-shopper.mjs
import { chromium } from 'playwright';

const shop = process.env.BW_SHOP;
const pay = process.env.BW_PAY ?? 'prepayment';
const product = process.env.BW_PRODUCT ?? '/Main-product/SWDEMO10001';
const labels = { prepayment: /prepayment|paid in advance|vorkasse/i, invoice: /invoice|rechnung/i, cash: /cash on delivery|nachnahme/i, paypal: /^\s*paypal\s*$/im };

if (!/^https:\/\/shopware-test\./.test(shop ?? '') || !labels[pay]) {
  throw new Error('shopper_not_configured');
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ locale: 'en-GB' });
const result = { pay, order_received: false, order: null, error: null };
let paypalWindow = null;

try {
  await page.goto(shop + product, { waitUntil: 'load', timeout: 45000 });
  await page.locator('form.buy-widget button.btn-buy:not([tabindex="-1"])').first().click();
  await page.waitForTimeout(2500);
  await page.goto(`${shop}/checkout/cart`, { waitUntil: 'load', timeout: 45000 });
  await page.locator('a.begin-checkout-btn').first().click();

  const form = page.locator('form.register-form');
  await form.waitFor({ timeout: 20000 });
  const salutation = form.locator('select[name="salutationId"]');
  if (await salutation.count()) await salutation.selectOption({ index: 1 });
  for (const [name, value] of [
    ['billingAddress[firstName]', 'Test'], ['firstName', 'Test'],
    ['billingAddress[lastName]', 'Käufer'], ['lastName', 'Käufer'],
    ['email', `shopper+${Date.now()}@example.com`],
    ['billingAddress[street]', 'Teststraße 5'], ['billingAddress[zipcode]', '10115'],
    ['billingAddress[city]', 'Berlin'],
  ]) {
    const field = form.locator(`[name="${name}"]`);
    if (await field.count() && await field.isVisible()) await field.fill(value);
  }
  const country = form.locator('select[name="billingAddress[countryId]"]');
  if (await country.count()) await country.selectOption({ label: 'Germany' }).catch(async () => country.selectOption({ index: 1 }));
  const account = form.locator('input[name="createCustomerAccount"]');
  if (await account.count() && await account.isChecked()) await account.uncheck();
  await form.locator('button[type="submit"]').last().click();
  await page.locator('body.is-act-confirmpage').waitFor({ timeout: 30000 });

  const cookies = page.getByRole('button', { name: /only technically required|nur technisch notwendige/i });
  if (await cookies.count()) await cookies.first().click().catch(() => null);
  const more = page.locator('.confirm-checkout-collapse-trigger');
  if (await more.count() && await more.first().isVisible()) await more.first().click().catch(() => null);
  const method = page.locator('.payment-method').filter({ hasText: labels[pay] }).first();
  await method.scrollIntoViewIfNeeded().catch(() => null);
  if (!(await method.locator('input[type="radio"]').isChecked())) {
    await method.locator('label').first().click();
    await page.waitForLoadState('load');
    await page.waitForTimeout(2000);
  }
  const tos = page.locator('#tos');
  if (await tos.count() && !(await tos.isChecked())) {
    await page.locator('label[for="tos"]').first().click();
  }
  if (pay === 'paypal') {
    // SwagPayPal's Smart Payment Buttons: the PayPal button opens a PayPal window; the sandbox buyer signs in and
    // approves there, the window closes and the shop submits the order itself.
    let buttonFrame = null;
    for (let i = 0; i < 30 && buttonFrame === null; i++) {
      for (const frame of page.frames()) {
        if (/paypal\.com\/smart\/buttons/.test(frame.url()) && await frame.locator('[data-funding-source="paypal"]').count().catch(() => 0) > 0) {
          buttonFrame = frame;
          break;
        }
      }
      if (buttonFrame === null) await page.waitForTimeout(1000);
    }
    if (buttonFrame === null) {
      result.frames = page.frames().map((frame) => frame.url().replace(/[?#].*$/, '')).filter((url) => url.includes('paypal'));
      throw new Error('paypal_button_not_found');
    }
    const [popup] = await Promise.all([page.waitForEvent('popup', { timeout: 60000 }), buttonFrame.locator('[data-funding-source="paypal"]').first().click({ timeout: 30000, force: true })]);
    paypalWindow = popup;
    await popup.waitForLoadState('domcontentloaded');
    const email = popup.locator('#email');
    await email.waitFor({ timeout: 90000 });
    await email.fill(process.env.BUYER_EMAIL);
    const password = popup.getByLabel('Password', { exact: true });
    const next = popup.getByRole('button', { name: 'Next', exact: true });
    if (!(await password.isVisible())) {
      await email.press('Enter');
      await password.waitFor({ state: 'visible', timeout: 15000 }).catch(async () => { if (await next.count()) await next.click({ force: true }); });
    }
    await password.waitFor({ state: 'visible', timeout: 60000 });
    await popup.waitForTimeout(1500);
    await password.click();
    await password.pressSequentially(process.env.BUYER_PASSWORD, { delay: 30 });
    await password.press('Enter');
    const approve = popup.getByRole('button', { name: /^(Pay|Pay Now|Complete Purchase|Continue to Review Order|Continue|Agree & Continue|Save and Continue)$/ }).first();
    await approve.waitFor({ timeout: 90000 });
    // Sandbox may first show «Continue to Review Order» and then «Pay Now»: confirm until PayPal closes its window.
    for (let i = 0; i < 4 && !popup.isClosed(); i++) {
      await popup.waitForTimeout(2000);
      if (popup.isClosed()) break;
      const button = popup.getByRole('button', { name: /^(Pay|Pay Now|Complete Purchase|Continue to Review Order|Continue|Agree & Continue|Save and Continue)$/ }).first();
      if (await button.count() === 0) break;
      await button.click().catch(() => null);
      await popup.waitForEvent('close', { timeout: 15000 }).catch(() => null);
    }
    result.paypal_window_closed = popup.isClosed();
  } else {
    await page.locator('#confirmFormSubmit').first().click();
  }

  await page.waitForURL(/\/checkout\/finish/, { timeout: 120000 });
  result.order_received = true;
  result.order = (await page.locator('body').innerText()).match(/(?:order number|bestellnummer)\D{0,10}(\d{5,})/i)?.[1] ?? null;
} catch (error) {
  result.error = String(error?.message ?? error).split('\n')[0].slice(0, 300);
  result.url = page.url().replace(/[?#].*$/, '');
  result.buttons = await page.locator('#confirmFormSubmit, [class*="paypal"] button, [id*="paypal"], [class*="swag-paypal"]').evaluateAll((els) => els.slice(0, 8).map((e) => `${e.tagName}#${e.id}.${String(e.className).slice(0, 60)} visible=${e.offsetParent !== null} disabled=${e.disabled === true}`)).catch(() => null);
  if (process.env.BW_SHOT) await page.screenshot({ path: process.env.BW_SHOT }).catch(() => null);
  if (process.env.BW_SHOT && paypalWindow && !paypalWindow.isClosed()) {
    result.paypal_url = paypalWindow.url().replace(/[?#].*$/, '');
    await paypalWindow.screenshot({ path: process.env.BW_SHOT.replace('.png', '-paypal.png') }).catch(() => null);
  }
}

console.log(JSON.stringify(result));
await browser.close();
