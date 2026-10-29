import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { after, before, describe, it } from 'node:test';

import { chromium } from 'playwright';

import { ADAPTER_VERSION } from '../src/adapter/shopware.js';
import { runAttempt } from '../src/runAttempt.js';

const lineItem = 'a'.repeat(32);
let browser = null;
let server = null;
let origin = '';
let mode = 'healthy';

const productPage = `<!doctype html><html><body class="is-ctl-product"><h1>Mark Adams No. 1</h1>
  <form class="buy-widget" onsubmit="return false"><input type="hidden" name="lineItems[${lineItem}][id]" value="${lineItem}">
  <button class="btn-buy" type="button" onclick="fetch('/checkout/line-item/add', { method: 'POST', body: 'x=1' })">In den Warenkorb</button></form>
  <script>fetch('/widgets/checkout/info')</script></body></html>`;

const errorPage = '<!doctype html><html><body class="is-ctl-error"><header>Tabak Hero</header><main>Leider ist etwas schief gelaufen</main><footer></footer></body></html>';

function handle(request, response) {
  const inCart = /(?:^|;\s*)cart=1/.test(request.headers.cookie ?? '');

  if (request.url === '/checkout/line-item/add' && request.method === 'POST') {
    response.writeHead(200, { 'content-type': 'text/html', 'set-cookie': 'cart=1; Path=/' }).end('<div class="offcanvas">ok</div>');
  } else if (request.url === '/widgets/checkout/info') {
    response.writeHead(inCart && mode === 'widget_error' ? 500 : 200, { 'content-type': 'text/html' }).end('');
  } else if (request.url === '/product') {
    const broken = inCart && mode === 'page_error';
    response.writeHead(broken ? 500 : 200, { 'content-type': 'text/html' }).end(broken ? errorPage : productPage);
  } else {
    response.writeHead(404).end();
  }
}

before(async () => {
  browser = await chromium.launch().catch(() => null);
  server = createServer(handle);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
});

after(async () => {
  await browser?.close();
  await new Promise((resolve) => server.close(resolve));
});

function lease() {
  return {
    attempt_id: 'a-1',
    absolute_deadline_at: new Date(Date.now() + 90000).toISOString(),
    network_policy: { allowed_origins: [origin], payment_providers: false, quiet_paths: [] },
    scenario: {
      platform: 'shopware',
      adapter_version: ADAPTER_VERSION,
      store_origin: origin,
      product_url: `${origin}/product`,
      cart_url: `${origin}/checkout/cart`,
      checkout_url: `${origin}/checkout/confirm`,
      steps: [
        { code: 'product', timeout_ms: 5000 },
        { code: 'add_to_cart', timeout_ms: 5000 },
        { code: 'product_again', timeout_ms: 5000 },
      ],
    },
  };
}

async function attempt(t, storeMode) {
  if (browser === null) {
    t.skip('Chromium is not installed');

    return null;
  }

  mode = storeMode;

  return runAttempt({ browser, lease: lease(), insecureLocal: true });
}

describe('product page opened again with the product in the cart', () => {
  it('finds a store whose product page fails once the product is in the cart', async (t) => {
    const result = await attempt(t, 'page_error');
    if (result === null) return;

    assert.equal(result.status, 'failed');
    assert.equal(result.error_code, 'site_failure');
    assert.deepEqual(result.steps.map((step) => [step.code, step.status]), [['product', 'passed'], ['add_to_cart', 'passed'], ['product_again', 'failed']]);
    assert.equal(result.steps[2].assertions[0].detail_code, 'product_page_fails_with_cart');
  });

  it('passes a healthy store', async (t) => {
    const result = await attempt(t, 'healthy');
    if (result === null) return;

    assert.equal(result.status, 'passed');
    assert.deepEqual(result.steps[2].assertions, [{ code: 'product_page_with_cart', passed: true }]);
  });

  it('passes but warns when a request of the store fails with 500 on the way', async (t) => {
    const result = await attempt(t, 'widget_error');
    if (result === null) return;

    assert.equal(result.status, 'passed');
    assert.deepEqual(result.steps[2].assertions, [{ code: 'product_page_with_cart', passed: true }, { code: 'store_server_error', passed: true, detail_code: 'http_500' }]);
    assert.ok(result.steps[2].network_summary.some((entry) => entry.path === '/widgets/checkout/info' && entry.status === 500));
  });
});
