import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { after, before, test } from 'node:test';
import { chromium } from 'playwright';
import { runAttempt } from '../src/runAttempt.js';

let server;
let browser;
let origin;
let cart = false;
let fault = 'none';
let fullFlow = false;
const productId = 'a'.repeat(32);

before(async () => {
  server = createServer((request, response) => {
    const path = new URL(request.url, origin).pathname;
    const send = (cls, body) => { response.setHeader('content-type', 'text/html'); response.end(`<html><body class="${cls}">${body}</body></html>`); };
    if (request.method === 'POST' && path === '/checkout/line-item/add') {
      cart = true;
      response.writeHead(303, { location: '/detail/product' }); response.end();
    } else if (path === '/detail/product') {
      send('is-ctl-product', `<div class="product-detail-buy"><form class="buy-widget" method="post" action="/checkout/line-item/add"><input name="lineItems[${productId}][id]" value="${productId}"><button class="btn-buy" ${fault === 'product_disabled' ? 'disabled' : ''}>Buy</button></form></div>`);
    } else if (path === '/checkout/cart') {
      const checkout = `<a class="begin-checkout-btn" href="/checkout/confirm" ${fault === 'disabled' ? 'aria-disabled="true"' : ''}>Go to checkout</a>${fault === 'script' ? '<script>document.querySelector("a.begin-checkout-btn").addEventListener("click", e => e.preventDefault())</script>' : ''}`;
      const lineId = fault === 'wrong_item' ? 'b'.repeat(32) : productId;
      const amount = fault === 'zero_amount' ? '€0.00' : '€19.99';
      send('is-act-cartpage', cart ? `<div class="line-item-product"><a class="line-item-label" href="/detail/${fault === 'wrong_item' ? 'other' : 'product'}">Product</a><form action="/checkout/line-item/change-quantity/${lineId}"></form><span class="line-item-total-price-value">${amount}</span></div><span class="checkout-aside-summary-value checkout-aside-summary-total">${amount}</span>${checkout}` : '<p>Empty</p>');
    } else if (path === '/checkout/confirm') {
      if (fullFlow) {
        const shipping = fault === 'missing_shipping' ? '' : `<div class="shipping-methods"><input class="shipping-method-input" name="shippingMethodId" type="radio" ${fault === 'disabled_shipping' ? 'disabled' : 'checked'}></div>`;
        const payment = fault === 'missing_payment' ? '' : fault === 'theme' ? '<div class="pay-options"><input class="pay-option" name="paymentMethodId" type="radio" checked></div>' : `<div class="payment-methods"><input class="payment-method-input" name="paymentMethodId" type="radio" ${fault === 'disabled_payment' ? 'disabled' : 'checked'}></div>`;
        const submit = fault === 'theme' ? '<button class="buy-now-final" type="submit">Order</button>' : '<button id="confirmFormSubmit" type="submit">Order</button>';
        send('is-act-confirmpage', `${shipping}${payment}${submit}`);
      } else send('is-act-checkoutregisterpage', '<form class="login-form"></form><form class="register-form"></form>');
    } else { response.writeHead(404); response.end(); }
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, chromiumSandbox: false });
});

after(async () => {
  await browser?.close();
  await new Promise((resolve) => server?.close(resolve));
});

async function check(nextFault, complete = false, layout = null) {
  cart = false;
  fault = nextFault;
  fullFlow = complete;
  const steps = ['product', 'add_to_cart', 'cart', 'checkout'];
  if (complete) steps.push('shipping', 'payment_form');
  return runAttempt({
    browser, insecureLocal: true,
    lease: {
      absolute_deadline_at: new Date(Date.now() + 60000).toISOString(),
      scenario: {
        adapter_version: 'shopware-payment-form@1', platform: 'shopware', store_origin: origin,
        product_url: `${origin}/detail/product`, cart_url: `${origin}/checkout/cart`, checkout_url: `${origin}/checkout/confirm`,
        checkout_access: complete ? 'test_customer' : 'stop_before_sign_in', synthetic_location: null, expect_checkout_script: false, checkout_layout: layout,
        steps: steps.map((code) => ({ code, timeout_ms: 6000 })),
      },
      network_policy: { allowed_origins: [origin], quiet_paths: [] },
    },
  });
}

test('healthy checkout control navigates to sign-in', async () => {
  const result = await check('none');
  assert.equal(result.status, 'passed');
  assert.ok(result.steps.find((step) => step.code === 'cart').assertions.some((assertion) => assertion.code === 'checkout_control_works' && assertion.passed));
});

test('disabled product button without a sold-out marker is a site failure', async () => {
  const result = await check('product_disabled');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps[0].error_code, 'site_failure');
});

test('disabled cart checkout control is a site failure', async () => {
  const result = await check('disabled');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').error_code, 'site_failure');
});

test('cart script preventing navigation is a site failure', async () => {
  const result = await check('script');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').error_code, 'site_failure');
});

test('a different product in the cart is a site failure', async () => {
  const result = await check('wrong_item');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').error_code, 'site_failure');
});

test('zero cart amount is a site failure', async () => {
  const result = await check('zero_amount');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').error_code, 'site_failure');
});

test('shipping and payment are selectable on the confirm page', async () => {
  const result = await check('none', true);
  assert.equal(result.status, 'passed');
  assert.ok(result.steps.find((step) => step.code === 'shipping').assertions.some((assertion) => assertion.code === 'delivery_option_selectable'));
  assert.ok(result.steps.find((step) => step.code === 'payment_form').assertions.some((assertion) => assertion.code === 'payment_method_selectable'));
});

for (const [faultCode, stepCode] of [['missing_shipping', 'shipping'], ['disabled_shipping', 'shipping'], ['missing_payment', 'payment_form'], ['disabled_payment', 'payment_form']]) {
  test(`${faultCode} fails the ${stepCode} step`, async () => {
    const result = await check(faultCode, true);
    assert.equal(result.status, 'failed');
    assert.equal(result.steps.find((step) => step.code === stepCode).error_code, 'site_failure');
  });
}

test('a third-party theme passes with the store layout and is not called broken without it', async () => {
  const layout = { submit_button: 'button.buy-now-final', payment_method: 'input.pay-option' };
  const themed = await check('theme', true, layout);
  assert.equal(themed.status, 'passed');
  assert.ok(themed.steps.find((step) => step.code === 'payment_form').assertions.some((assertion) => assertion.code === 'payment_method_selectable' && assertion.passed));

  const stale = await check('theme', true, { submit_button: '#renamed-again', payment_method: 'input.pay-option' });
  const step = stale.steps.find((candidate) => candidate.code === 'payment_form');
  assert.equal(stale.status, 'inconclusive');
  assert.equal(step.error_code, 'selector_changed');
});
