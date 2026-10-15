import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const origin = process.env.BW_SHOP_ORIGIN;
const productId = process.env.BW_PRODUCT_ID;
const cartPageId = process.env.BW_CART_PAGE_ID;
const checkoutPageId = process.env.BW_CHECKOUT_PAGE_ID;
const mode = process.env.BW_FAULT_MODE ?? 'healthy';
const supported = new Set(['healthy', 'product_button', 'cart_button', 'cart_script', 'cart_price', 'cannot_buy', 'gateway_no_response']);

if (process.env.BW_PURCHASE_LAB !== '1' || origin !== 'http://wordpress' || !productId || !cartPageId || !checkoutPageId || !supported.has(mode)) {
  throw new Error('purchase_lab_not_configured');
}

const browser = await chromium.launch({ headless: true, chromiumSandbox: true });
const context = await browser.newContext({
  locale: 'en-US',
  extraHTTPHeaders: mode === 'healthy' ? {} : { 'X-BW-Fault-Mode': mode },
});
const page = await context.newPage();
const result = { mode, observed: 'unknown', order_received: false, expected: '' };
const minor = (label) => {
  const normalized = label.replace(/\s/g, '').match(/(\d+)[,.](\d{2})(?!\d)/);
  assert.ok(normalized, `unreadable_price:${label}`);
  return BigInt(normalized[1]) * 100n + BigInt(normalized[2]);
};

try {
  await page.goto(`${origin}/?p=${productId}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const add = page.locator('form.cart .single_add_to_cart_button').first();
  await add.waitFor({ state: 'visible', timeout: 10000 });
  const catalogMinor = minor(await page.locator('.woocommerce-Price-amount').first().innerText());

  if (mode === 'product_button') {
    assert.equal(await add.isDisabled(), true);
    result.observed = 'product_button_disabled';
    result.expected = 'product_button_disabled';
  } else {
    await add.click();
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => null);
    await page.goto(`${origin}/?page_id=${cartPageId}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.locator('.woocommerce-cart-form .cart_item:visible').first().waitFor({ timeout: 10000 });

    if (mode === 'cart_price') {
      const cartMinor = minor(await page.locator('.cart-subtotal .woocommerce-Price-amount').first().innerText());
      assert.notEqual(cartMinor, catalogMinor);
      assert.equal(cartMinor - catalogMinor, 200n);
      result.observed = 'cart_price_differs_from_catalog';
      result.expected = 'cart_price_differs_from_catalog';
    } else {
      const checkoutLink = page.locator('a.checkout-button:visible').first();
      await checkoutLink.waitFor({ timeout: 10000 });

      if (mode === 'cart_button') {
        assert.equal(await checkoutLink.getAttribute('aria-disabled'), 'true');
        result.observed = 'cart_checkout_button_disabled';
        result.expected = 'cart_checkout_button_disabled';
      } else if (mode === 'cart_script') {
        await checkoutLink.click({ timeout: 5000 });
        assert.equal(new URL(page.url()).searchParams.get('page_id'), cartPageId);
        result.observed = 'cart_script_blocks_checkout';
        result.expected = 'cart_script_blocks_checkout';
      } else {
        await checkoutLink.click({ timeout: 10000 });
        await page.locator('form.checkout.woocommerce-checkout').waitFor({ timeout: 15000 });

        if (mode === 'cannot_buy') {
          await page.waitForTimeout(1500);
          assert.equal(await page.locator('input[name="payment_method"]').count(), 0);
          result.observed = 'no_payment_methods';
          result.expected = 'no_payment_methods';
        } else {
          const billing = {
            billing_first_name: 'Matrix', billing_last_name: 'Tester', billing_address_1: 'Teststrasse 1',
            billing_city: 'Berlin', billing_postcode: '10115', billing_email: 'matrix@example.test', billing_phone: '030000000',
          };
          await page.locator('#billing_country').selectOption('DE');
          for (const [key, value] of Object.entries(billing)) await page.locator(`#${key}`).fill(value);
          const method = mode === 'gateway_no_response' ? 'bw_test_no_response' : 'bacs';
          await page.locator(`label[for="payment_method_${method}"]`).click();
          await page.waitForTimeout(500);
          const checkoutResponse = page.waitForResponse((response) => response.url().includes('wc-ajax=checkout'), { timeout: 30000 });
          await page.locator('#place_order').click();
          const response = await checkoutResponse;

          if (mode === 'gateway_no_response') {
            assert.equal(response.status(), 504);
            result.observed = 'gateway_response_missing';
            result.expected = 'gateway_response_missing';
          } else {
            assert.equal(response.status(), 200);
            await page.waitForURL(/order-received/, { timeout: 30000 });
            result.observed = 'order_received';
            result.expected = 'order_received';
            result.order_received = true;
          }
        }
      }
    }
  }

  assert.equal(result.observed, result.expected);
  console.log(`BW_PURCHASE_RESULT=${JSON.stringify(result)}`);
} finally {
  await browser.close();
}
