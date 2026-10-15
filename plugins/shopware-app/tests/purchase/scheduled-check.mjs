// The production Shopware adapter against the local storefront; stops before buyer sign-in.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { runAttempt } from './src/runAttempt.js';

const origin = process.env.BW_SHOP_ORIGIN;
const product = process.env.BW_PRODUCT_ID;
const access = process.env.BW_CHECK_ACCESS ?? 'stop_before_sign_in';
if (origin !== 'http://localhost' || !/^[a-f0-9]{32}$/.test(product ?? '')) throw new Error('local_shop_only');
if (!['stop_before_sign_in', 'test_customer', 'guest'].includes(access)) throw new Error('unsupported_access');

const steps = ['product', 'add_to_cart', 'cart', 'checkout'];
if (access === 'test_customer') steps.push('sign_in', 'shipping', 'payment_form');
if (access === 'guest') steps.push('guest_details', 'shipping', 'payment_form');

const browser = await chromium.launch({ headless: true, chromiumSandbox: true });
try {
  const result = await runAttempt({
    browser, insecureLocal: true,
    lease: {
      absolute_deadline_at: new Date(Date.now() + 90000).toISOString(),
      scenario: {
        adapter_version: 'shopware-payment-form@1', platform: 'shopware', store_origin: origin,
        product_url: `${origin}/detail/${product}`, cart_url: `${origin}/checkout/cart`, checkout_url: `${origin}/checkout/confirm`,
        checkout_access: access, synthetic_location: { country: 'DE', postcode: '10115' }, expect_checkout_script: false,
        ...(access === 'test_customer' ? { sign_in: { email: process.env.BW_TEST_EMAIL, password: process.env.BW_TEST_PASSWORD } } : {}),
        steps: steps.map((code) => ({ code, timeout_ms: 20000 })),
      },
      network_policy: { allowed_origins: [origin], quiet_origins: [] },
    },
  });
  console.log(`BW_SCHEDULED_RESULT=${JSON.stringify({access,status:result.status, steps:result.steps.map(s=>`${s.code}:${s.status}`)})}`);
  assert.equal(result.status, 'passed');
  for (const code of ['cart_expected_product', 'cart_positive_amount', 'checkout_control_works']) {
    assert.ok(result.steps.find(s=>s.code === 'cart')?.assertions?.some(a=>a.code === code && a.passed), code);
  }
  if (access !== 'stop_before_sign_in') {
    assert.ok(result.steps.find(s=>s.code === 'shipping')?.assertions?.some(a=>a.code === 'delivery_option_selectable' && a.passed));
    assert.ok(result.steps.find(s=>s.code === 'payment_form')?.assertions?.some(a=>a.code === 'payment_method_selectable' && a.passed));
  }
} finally {
  await browser.close();
}
