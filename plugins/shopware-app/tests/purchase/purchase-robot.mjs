// Local-only real shopper. This is separate from the scheduled worker, which must never submit an order.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const origin = process.env.BW_SHOP_ORIGIN;
const productId = process.env.BW_PRODUCT_ID;
const mode = process.env.BW_FAULT_MODE ?? 'healthy';
const modes = new Set(['healthy', 'product_button', 'cart_button', 'cart_script', 'cart_price', 'cannot_buy', 'checkout_response_missing']);

if (process.env.BW_PURCHASE_LAB !== '1' || origin !== 'http://localhost' || !/^[a-f0-9]{32}$/.test(productId ?? '') || !modes.has(mode)) {
  throw new Error('purchase_lab_not_configured');
}

const minor = (label) => {
  const match = label.replace(/\s/g, '').match(/(\d+)[,.](\d{2})(?!\d)/);
  assert.ok(match, 'unreadable_price');
  return BigInt(match[1]) * 100n + BigInt(match[2]);
};

const browser = await chromium.launch({ headless: true, chromiumSandbox: true });
const page = await (await browser.newContext({ locale: 'en-US' })).newPage();
const result = { mode, observed: 'unknown', order_received: false };

try {
  await page.goto(`${origin}/detail/${productId}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const buy = page.locator('form.buy-widget button.btn-buy:not([tabindex="-1"])').first();
  await buy.waitFor({ state: 'visible', timeout: 15000 });
  const catalogMinor = minor(await page.locator('.product-detail-price').first().innerText());

  if (mode === 'product_button') {
    await buy.evaluate((element) => { element.disabled = true; element.setAttribute('aria-disabled', 'true'); });
    assert.equal(await buy.isDisabled(), true);
    result.observed = 'product_button_disabled';
  } else {
    const added = page.waitForResponse((response) => response.request().method() === 'POST' && /\/checkout\/line-item\/add\/?$/.test(new URL(response.url()).pathname), { timeout: 15000 });
    await buy.click();
    assert.ok((await added).status() < 400, 'add_to_cart_failed');
    await page.goto(`${origin}/checkout/cart`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.locator('.line-item-product').first().waitFor({ timeout: 15000 });

    if (mode === 'cart_price') {
      const subtotal = page.locator('.line-item-total-price-value').first();
      await subtotal.evaluate((element) => { element.textContent = '€21.99'; });
      const cartMinor = minor(await subtotal.innerText());
      assert.notEqual(cartMinor, catalogMinor);
      assert.equal(cartMinor - catalogMinor, 200n);
      result.observed = 'cart_price_differs_from_catalog';
    } else {
      const checkout = page.locator('a.begin-checkout-btn').first();
      await checkout.waitFor({ state: 'visible', timeout: 10000 });

      if (mode === 'cart_button') {
        await checkout.evaluate((element) => { element.style.pointerEvents = 'none'; element.setAttribute('aria-disabled', 'true'); });
        assert.equal(await checkout.getAttribute('aria-disabled'), 'true');
        result.observed = 'cart_checkout_button_disabled';
      } else if (mode === 'cart_script') {
        await checkout.evaluate((element) => element.addEventListener('click', (event) => { event.preventDefault(); event.stopImmediatePropagation(); }, true));
        await checkout.click();
        await page.waitForTimeout(500);
        assert.equal(new URL(page.url()).pathname, '/checkout/cart');
        result.observed = 'cart_script_blocks_checkout';
      } else {
        await checkout.click();
        await page.locator('form.register-form').waitFor({ timeout: 15000 });
        const form = page.locator('form.register-form');
        const salutation = form.locator('select[name="salutationId"]');
        if (await salutation.count()) await salutation.selectOption({ index: 1 });
        for (const [name, value] of [
          ['billingAddress[firstName]', 'Matrix'], ['firstName', 'Matrix'],
          ['billingAddress[lastName]', 'Tester'], ['lastName', 'Tester'],
          ['email', `matrix-${Date.now()}@example.test`],
          ['billingAddress[street]', 'Teststraße 1'], ['billingAddress[zipcode]', '10115'],
          ['billingAddress[city]', 'Berlin'],
        ]) {
          const field = form.locator(`[name="${name}"]`);
          if (await field.count() && await field.isVisible()) await field.fill(value);
        }
        const country = form.locator('select[name="billingAddress[countryId]"]');
        if (await country.count() && await country.inputValue() === '') await country.selectOption({ index: 1 });
        const account = form.locator('input[name="createCustomerAccount"]');
        if (await account.count() && await account.isChecked()) await account.uncheck();
        await form.locator('button[type="submit"]').last().click();
        await page.locator('body.is-act-confirmpage').waitFor({ timeout: 30000 });

        const submit = page.locator('#confirmFormSubmit').first();
        await submit.waitFor({ state: 'visible', timeout: 15000 });
        if (mode === 'cannot_buy') {
          await submit.evaluate((element) => { element.disabled = true; element.setAttribute('aria-disabled', 'true'); });
          assert.equal(await submit.isDisabled(), true);
          result.observed = 'order_submit_disabled';
        } else {
          await page.locator('#tos').check({ force: true });
          if (mode === 'checkout_response_missing') {
            await page.route('**/checkout/order', async (route) => {
              await route.fetch(); // Let Shopware create the order, then lose the response.
              await route.abort('failed');
            });
            await submit.click();
            await page.waitForTimeout(1500);
            assert.notEqual(new URL(page.url()).pathname, '/checkout/finish');
            result.observed = 'checkout_response_missing';
          } else {
            await submit.click();
            await page.waitForURL(/\/checkout\/finish/, { timeout: 60000 });
            result.observed = 'order_received';
            result.order_received = true;
          }
        }
      }
    }
  }

  console.log(`BW_PURCHASE_RESULT=${JSON.stringify(result)}`);
} finally {
  await browser.close();
}
