// A real shopper on the storefront (not the browser check): goes to the confirm page as a guest and submits the
// order. `broken` adds a page script that cancels the order form, as a broken theme script would («Submit order»
// does nothing), and waits past the stall time.
import { chromium } from 'playwright';

const mode = process.argv[2];
const product = process.argv[3];
const browser = await chromium.launch();
const context = await browser.newContext({ locale: 'en-US' });
const page = await context.newPage();

if (mode === 'broken') {
  await context.addInitScript(() => {
    document.addEventListener('submit', (event) => {
      if (event.target && event.target.id === 'confirmOrderForm') {
        event.preventDefault();
      }
    }, true);
  });
}

try {
await page.goto(product);
await page.locator('.btn-buy').first().click();
await page.waitForTimeout(2000);
await page.goto(new URL('/checkout/confirm', product).toString());
const form = page.locator('form.register-form');
await form.locator('select[name="salutationId"]').selectOption({ index: 1 }).catch(() => null);

for (const [name, value] of [['billingAddress[firstName]', 'Real'], ['firstName', 'Real'], ['billingAddress[lastName]', 'Shopper'], ['lastName', 'Shopper'], ['email', `shopper-${Date.now()}@example.com`], ['billingAddress[street]', 'Teststr 1'], ['billingAddress[zipcode]', '10115'], ['billingAddress[city]', 'Berlin']]) {
  const field = form.locator(`[name="${name}"]`);

  if ((await field.count()) > 0 && (await field.isVisible())) {
    await field.fill(value);
  }
}

const country = form.locator('select[name="billingAddress[countryId]"]');

if ((await country.count()) > 0 && (await country.inputValue()) === '') {
  await country.selectOption({ index: 1 });
}

await form.locator('button[type=submit]').last().click();
await page.waitForURL(/checkout\/confirm/, { timeout: 30000 });
await page.waitForTimeout(3000);
const onConfirm = await page.evaluate(() => window.BusinessWatchdogCheckout ?? null);
await page.locator('#tos').check({ force: true }).catch(() => null);
await page.locator('#confirmFormSubmit').click();

if (mode === 'broken') {
  await page.waitForTimeout(25000);
} else {
  await page.waitForURL(/checkout\/finish/, { timeout: 60000 });
  await page.waitForTimeout(3000);
}

const after = await page.evaluate(() => window.BusinessWatchdogCheckout ?? null).catch(() => null);
console.log('BW_CUSTOMER=' + JSON.stringify({ mode, confirm: onConfirm, after, url: new URL(page.url()).pathname }));
} catch (error) {
  console.log('BW_CUSTOMER=' + JSON.stringify({ mode, error: String(error.message).split('\n')[0], url: page.url(), body: await page.evaluate(() => document.body.className).catch(() => null) }));
}
await browser.close();
