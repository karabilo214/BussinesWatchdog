// A real shopper on the WooCommerce store (not the browser check). buy: Classic checkout to the order-received page;
// broken: the checkout request fails, as if the checkout were broken (the shop shows an error); blocks: opens the Blocks
// checkout and waits until the payment form is ready.
import { chromium } from 'playwright';

const [mode, base, product, checkout, blocks] = process.argv.slice(2);
const browser = await chromium.launch();
const context = await browser.newContext({ locale: 'en-US' });
const page = await context.newPage();
let result = { mode };

try {
  if (mode === 'broken') {
    await context.route(/wc-ajax=checkout/, (route) => route.abort());
  }

  await page.goto(`${base}/?add-to-cart=${product}`);
  await page.goto(mode === 'blocks' ? `${base}/?page_id=${blocks}` : `${base}/?page_id=${checkout}`);

  if (mode === 'blocks') {
    await page.waitForTimeout(8000);
  } else {
    await page.locator('form.checkout').waitFor({ timeout: 30000 });

    for (const [id, value] of [['billing_first_name', 'Real'], ['billing_last_name', 'Shopper'], ['billing_address_1', 'Teststr 1'], ['billing_city', 'Berlin'], ['billing_postcode', '10115'], ['billing_phone', '030123456'], ['billing_email', `shopper-${Date.now()}@example.com`]]) {
      const field = page.locator(`#${id}`);

      if ((await field.count()) > 0 && (await field.isVisible())) {
        await field.fill(value);
      }
    }

    await page.locator('#payment_method_bacs').check({ force: true }).catch(() => null);
    await page.waitForTimeout(2500);
    result.before = await page.evaluate(() => window.BusinessWatchdogCheckout ?? null);
    await page.locator('#place_order').click();

    if (mode === 'broken') {
      await page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout').first().waitFor({ timeout: 30000 }).catch(() => null);
      await page.waitForTimeout(3000);
    } else {
      await page.waitForURL(/order-received/, { timeout: 60000 });
      await page.waitForTimeout(3000);
    }
  }

  result = { ...result, after: await page.evaluate(() => window.BusinessWatchdogCheckout ?? null), url: page.url().replace(/key=[^&]+/, 'key=…') };
} catch (error) {
  result.error = String(error.message).split('\n')[0];
  result.url = page.url();
}

console.log('BW_CUSTOMER=' + JSON.stringify(result));
await browser.close();
