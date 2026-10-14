import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { after, before, test } from 'node:test';
import { chromium } from 'playwright';

import { runAttempt } from '../src/runAttempt.js';

let browser;
let server;
let origin;
let cartWorks = true;
let cartCount = 0;
let shopware = false;

before(async () => {
  server = createServer((request, response) => {
    const path = new URL(request.url, origin).pathname;
    const send = (body) => {
      response.setHeader('Content-Type', 'text/html; charset=utf-8');
      response.end(`<html><head><meta name="generator" content="${shopware ? 'Shopware' : 'WooCommerce'}"></head><body><nav><a href="${shopware ? '/produkte/' : '/shop/'}">Shop</a><a href="${shopware ? '/checkout/cart' : '/cart/'}">Cart ${cartCount}</a>${shopware ? '' : '<a href="/checkout/">Checkout</a>'}</nav>${body}</body></html>`);
    };

    if (request.method === 'POST' && path === '/cookie-consent/accept') {
      response.writeHead(204);
      response.end();
    } else if (request.method === 'POST' && (path === '/product/1/' || path === '/checkout/line-item/add')) {
      cartCount += 1;
      response.writeHead(303, { Location: shopware ? '/custom-item/sku' : '/product/1/' });
      response.end();
    } else if (path === '/shop/') {
      send('<a href="/product/1/">Test product</a>');
    } else if (path === '/produkte/') {
      send('<div class="product-box"><a class="product-name" href="/custom-item/sku">Test product</a></div>');
    } else if (path === '/product/1/') {
      send('<form method="post"><button type="submit" name="add-to-cart" value="1">Add to cart</button></form>');
    } else if (path === '/custom-item/sku') {
      send('<form method="post" action="/checkout/line-item/add"><button type="submit">In den Warenkorb</button></form><div role="dialog" aria-modal="true" style="display:none;position:fixed;inset:0;background:white"><button type="button" onclick="fetch(\'/cookie-consent/accept\',{method:\'POST\'}).then(r => { if (r.ok) this.parentElement.remove() })">Alles akzeptieren</button></div><script>setTimeout(() => { document.querySelector("[role=dialog]").style.display = "block"; }, 350)</script>');
    } else if (path === '/cart/' || path === '/checkout/cart') {
      const checkoutLink = shopware ? `<a href="/account/order">Bestellungen</a>${'<a href="/info/">Info</a>'.repeat(240)}<a href="/checkout/confirm">Zur Kasse</a>` : '';
      send((cartWorks && cartCount > 0 ? '<div class="cart_item">Test product</div>' : '<p>Your cart is empty</p>') + checkoutLink);
    } else if (path === '/checkout/confirm') {
      response.writeHead(302, { Location: '/checkout/register' });
      response.end();
    } else if (path === '/checkout/' || path === '/checkout/register') {
      send('<form><input name="billing_email" type="email"></form>');
    } else {
      send('<h1>Store</h1>');
    }
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true, chromiumSandbox: false });
});

after(async () => {
  await browser?.close();
  await new Promise((resolve) => server?.close(resolve));
});

async function check() {
  return await runAttempt({
    browser,
    insecureLocal: true,
    lease: {
      absolute_deadline_at: new Date(Date.now() + 90000).toISOString(),
      scenario: {
        adapter_version: 'public-storefront@1',
        platform: 'public',
        store_origin: origin,
        target_url: origin,
        allowed_store_origins: [origin],
        steps: [{ code: 'public_home', timeout_ms: 10000 }, { code: 'public_checkout', timeout_ms: 30000 }],
      },
      network_policy: { allowed_origins: [origin], quiet_origins: [] },
    },
  });
}

test('reaches customer form only after a product is present in the cart page', async () => {
  cartCount = 0;
  cartWorks = true;
  shopware = false;
  const result = await check();

  assert.equal(result.status, 'passed');
  assert.equal(result.diagnostics.public_audit.products_found, 1);
  assert.equal(result.diagnostics.public_audit.cart_result, 'product_present');
  assert.equal(result.diagnostics.public_audit.checkout_result, 'customer_details_reached');
});

test('does not trust a changed cart counter when the cart page is empty', async () => {
  cartCount = 0;
  cartWorks = false;
  shopware = false;
  const result = await check();

  assert.notEqual(result.status, 'passed');
  assert.equal(result.diagnostics.public_audit.cart_result, 'add_to_cart_not_verified');
  assert.equal(result.diagnostics.public_audit.sampled_products[0].cart_verified, false);
  assert.equal(result.diagnostics.public_audit.checkout_result, 'not_tested');
});

test('follows Shopware product cards with custom URLs and stops at registration', async () => {
  cartCount = 0;
  cartWorks = true;
  shopware = true;
  const result = await check();

  assert.equal(result.status, 'passed');
  assert.equal(result.diagnostics.public_audit.platform, 'shopware_hint');
  assert.equal(result.diagnostics.public_audit.products_found, 1);
  assert.equal(result.diagnostics.public_audit.cart_result, 'product_present');
  assert.equal(result.diagnostics.public_audit.checkout_result, 'login_or_registration_reached');
});
