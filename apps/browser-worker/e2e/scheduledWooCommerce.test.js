import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { after, before, test } from 'node:test';
import { chromium } from 'playwright';
import { runAttempt } from '../src/runAttempt.js';

let server;
let browser;
let origin;
let cart = false;
let cartFault = 'none';

before(async () => {
  server = createServer(async (request, response) => {
    const path = new URL(request.url, origin).pathname;
    const send = (body) => { response.setHeader('content-type', 'text/html'); response.end(`<html><body class="woocommerce">${body}</body></html>`); };
    if (request.method === 'POST' && path === '/product/') {
      let body = '';
      for await (const chunk of request) body += chunk;
      cart = new URLSearchParams(body).get('attribute_pa_size') === 'small';
      response.writeHead(303, { location: '/product/' }); response.end();
    } else if (path === '/product/') {
      send('<form class="cart variations_form" method="post"><select name="attribute_pa_size" required><option value="">Choose</option><option value="small">Small</option></select><button class="single_add_to_cart_button" name="add-to-cart" value="1">Add</button></form>');
    } else if (path === '/cart/') {
      const checkout = `<a class="checkout-button" href="/checkout/" ${cartFault === 'disabled' ? 'aria-disabled="true"' : ''}>Checkout</a>${cartFault === 'script' ? '<script>document.querySelector("a.checkout-button").addEventListener("click", e => e.preventDefault())</script>' : ''}`;
      const price = cartFault === 'zero_amount' ? '$0.00' : '$10.00';
      const link = cartFault === 'wrong_item' ? '/other/' : '/product/';
      send(cart ? `<form class="woocommerce-cart-form"><div class="cart_item" style="display:none">Mobile</div><div class="cart_item"><a href="${link}">Small item</a><span class="product-subtotal"><span class="amount">${price}</span></span></div></form><div class="cart_totals"><div class="order-total"><span class="amount">${price}</span></div></div>${checkout}` : '<p class="cart-empty">Empty</p>');
    } else if (path === '/checkout/') {
      const shipping = cartFault === 'missing_shipping' ? '<div class="woocommerce-shipping-methods"></div>' : `<div class="woocommerce-shipping-methods"><input name="shipping_method[0]" type="radio" ${cartFault === 'disabled_shipping' ? 'disabled' : 'checked'}></div>`;
      const payment = cartFault === 'missing_payment' ? '' : `<li><input name="payment_method" type="radio" ${cartFault === 'disabled_payment' ? 'disabled' : 'checked'}></li>`;
      send(`<form class="checkout woocommerce-checkout"><select id="billing_country"><option value="DE">Germany</option></select><input id="billing_postcode">${shipping}<div id="payment"><ul class="payment_methods">${payment}</ul></div></form>`);
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

async function check(expectCheckoutScript = false, fault = 'none', location = false) {
  cart = false;
  cartFault = fault;
  return runAttempt({
    browser, insecureLocal: true,
    lease: {
      absolute_deadline_at: new Date(Date.now() + 90000).toISOString(),
      scenario: {
        adapter_version: 'woocommerce-payment-form@1', platform: 'woocommerce', store_origin: origin,
        product_url: `${origin}/product/`, cart_url: `${origin}/cart/`, checkout_url: `${origin}/checkout/`,
        synthetic_location: location ? { country: 'DE', postcode: '10115' } : null, expect_checkout_script: expectCheckoutScript,
        steps: ['product', 'add_to_cart', 'cart', 'checkout', 'shipping', 'payment_form'].map((code) => ({ code, timeout_ms: 10000 })),
      },
      network_policy: { allowed_origins: [origin], quiet_origins: [] },
    },
  });
}

test('selects a variation and recognises the visible cart line', async () => {
  const result = await check();
  assert.equal(result.status, 'passed');
  assert.equal(result.steps.find((step) => step.code === 'cart').status, 'passed');
});

test('missing expected checkout observation script cannot pass', async () => {
  const result = await check(true);
  assert.equal(result.status, 'inconclusive');
  const payment = result.steps.find((step) => step.code === 'payment_form');
  assert.equal(payment.status, 'inconclusive');
  assert.deepEqual(payment.assertions.find((assertion) => assertion.code === 'checkout_script_present'), { code: 'checkout_script_present', passed: false, detail_code: 'script_missing' });
});

test('disabled checkout control in the cart is a site failure', async () => {
  const result = await check(false, 'disabled');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').status, 'failed');
});

test('cart script that prevents checkout navigation is a site failure', async () => {
  const result = await check(false, 'script');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').status, 'failed');
});

test('a different WooCommerce product in the cart is a site failure', async () => {
  const result = await check(false, 'wrong_item');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').status, 'failed');
});

test('zero WooCommerce cart amount is a site failure', async () => {
  const result = await check(false, 'zero_amount');
  assert.equal(result.status, 'failed');
  assert.equal(result.steps.find((step) => step.code === 'cart').status, 'failed');
});

test('WooCommerce delivery and payment choices are selectable', async () => {
  const result = await check(false, 'none', true);
  assert.equal(result.status, 'passed');
  assert.ok(result.steps.find((step) => step.code === 'shipping').assertions.some((assertion) => assertion.code === 'delivery_option_selectable'));
  assert.ok(result.steps.find((step) => step.code === 'payment_form').assertions.some((assertion) => assertion.code === 'payment_method_selectable'));
});

for (const [faultCode, stepCode] of [['missing_shipping', 'shipping'], ['disabled_shipping', 'shipping'], ['missing_payment', 'payment_form'], ['disabled_payment', 'payment_form']]) {
  test(`WooCommerce ${faultCode} fails ${stepCode}`, async () => {
    const result = await check(false, faultCode, true);
    assert.equal(result.status, 'failed');
    assert.equal(result.steps.find((step) => step.code === stepCode).error_code, 'site_failure');
  });
}
