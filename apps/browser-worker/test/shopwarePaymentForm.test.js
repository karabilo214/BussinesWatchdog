import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';

import { chromium } from 'playwright';

import { createShopwareAdapter } from '../src/adapter/shopware.js';

const confirmPage = (submit) => `<body class="is-act-confirmpage"><div class="payment-methods"><input type="radio" class="payment-method-input" name="paymentMethodId" value="novalnet" checked></div>${submit}</body>`;

let browser = null;

before(async () => {
  browser = await chromium.launch().catch(() => null);
});

after(async () => {
  await browser?.close();
});

async function paymentForm(t, html, blockedPaymentScripts) {
  if (browser === null) {
    t.skip('Chromium is not installed');

    return null;
  }

  const page = await browser.newPage();
  t.after(() => page.close());
  await page.setContent(html);

  return createShopwareAdapter().payment_form(page, { timeout_ms: 2000 }, { scenario: {}, blockedPaymentScripts, blockedUnknownMutation: false, forbiddenOperation: null })
    .catch((error) => error);
}

describe('Shopware payment step without the payment provider scripts', () => {
  it('passes the path and names the payment form as not verified when the provider script was not loaded', async (t) => {
    const outcome = await paymentForm(t, confirmPage('<button id="confirmFormSubmit" disabled>Zahlungspflichtig bestellen</button>'), true);
    if (outcome === null) return;

    assert.deepEqual(outcome, { skipped: true, assertions: [{ code: 'payment_methods_not_verified', passed: true, detail_code: 'order_submit_unavailable' }] });
  });

  it('stays a store failure when no provider script was held back', async (t) => {
    const outcome = await paymentForm(t, confirmPage('<button id="confirmFormSubmit" disabled>Zahlungspflichtig bestellen</button>'), false);
    if (outcome === null) return;

    assert.equal(outcome.errorCode, 'site_failure');
    assert.equal(outcome.detailCode, 'order_submit_unavailable');
  });

  it('passes fully when the order can be submitted', async (t) => {
    const outcome = await paymentForm(t, confirmPage('<button id="confirmFormSubmit">Zahlungspflichtig bestellen</button>'), true);
    if (outcome === null) return;

    assert.equal(outcome[0].code, 'payment_method_selectable');
  });
});
