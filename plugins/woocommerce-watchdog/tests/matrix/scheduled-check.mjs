// Production scheduled adapter on a local WooCommerce lab; checkout is observed without order submission.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { runAttempt } from './src/runAttempt.js';

const origin = process.env.BW_SHOP_ORIGIN;
const product = process.env.BW_PRODUCT_ID;
const cart = process.env.BW_CART_PAGE_ID;
const checkout = process.env.BW_CHECKOUT_PAGE_ID;
if (origin !== 'http://wordpress' || !/^\d+$/.test(product ?? '') || !/^\d+$/.test(cart ?? '') || !/^\d+$/.test(checkout ?? '')) throw new Error('local_shop_only');

const browser = await chromium.launch({ headless: true, chromiumSandbox: true });
try {
  const result = await runAttempt({
    browser, insecureLocal: true,
    lease: {
      absolute_deadline_at: new Date(Date.now() + 90000).toISOString(),
      scenario: {
        adapter_version: 'woocommerce-payment-form@1', platform: 'woocommerce', store_origin: origin,
        product_url: `${origin}/?p=${product}`, cart_url: `${origin}/?page_id=${cart}`, checkout_url: `${origin}/?page_id=${checkout}`,
        synthetic_location: null, expect_checkout_script: false,
        steps: ['product', 'add_to_cart', 'cart', 'checkout', 'shipping', 'payment_form'].map((code) => ({ code, timeout_ms: 20000 })),
      },
      network_policy: { allowed_origins: [origin], quiet_origins: [] },
    },
  });
  console.log(`BW_SCHEDULED_RESULT=${JSON.stringify({ status: result.status, steps: result.steps.map((step) => `${step.code}:${step.status}:${step.error_code ?? ''}`) })}`);
  assert.equal(result.status, 'passed');
  for (const code of ['cart_expected_product', 'cart_positive_amount', 'checkout_control_works']) {
    assert.ok(result.steps.find((step) => step.code === 'cart')?.assertions?.some((assertion) => assertion.code === code && assertion.passed), code);
  }
  assert.ok(result.steps.find((step) => step.code === 'payment_form')?.assertions?.some((assertion) => assertion.code === 'payment_method_selectable' && assertion.passed));
} finally {
  await browser.close();
}
