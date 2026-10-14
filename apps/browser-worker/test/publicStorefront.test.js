import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { detectPlatformFromPublicSignals, publicConsentAction } from '../src/adapter/publicStorefront.js';

describe('public storefront platform detection', () => {
  it('detects WooCommerce public storefront signals', () => {
    const html = [
      '<body class="woocommerce-page">',
      '<script src="/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.js"></script>',
      '<link href="/wp-json/wc/store/v1/products">',
    ].join(' ');

    assert.equal(detectPlatformFromPublicSignals(html), 'woocommerce_hint');
  });

  it('detects Shopware signals from late hydration payloads', () => {
    const html = [
      '<main>Public storefront</main>',
      ' '.repeat(210000),
      'shopware:{endpoint:"https://example.test/store-api/"}',
      'sales_channel_context',
      'shopware_core_framework_data_abstraction_layer',
    ].join(' ');

    assert.equal(detectPlatformFromPublicSignals(html.slice(-200000)), 'shopware_hint');
  });

  it('does not label Shopify as WooCommerce or Shopware', () => {
    const html = [
      'Shopify.shop = "example.myshopify.com";',
      '<script src="https://cdn.shopify.com/s/files/1/theme.js"></script>',
    ].join(' ');

    assert.equal(detectPlatformFromPublicSignals(html), 'unknown');
  });
});

describe('public storefront consent handling', () => {
  it('accepts common cookie and age-gate labels', () => {
    for (const label of ['Accept all', 'Allow cookies', 'I am 18', 'Yes, enter', 'Alle akzeptieren', 'Ich bin 18', 'Tout accepter', 'Soy mayor']) {
      assert.equal(publicConsentAction(label), 'accept', label);
    }
  });

  it('does not click reject or commerce actions as consent', () => {
    for (const label of ['Reject all', 'No, leave', 'Under 18', 'Checkout', 'Add to cart', 'Login', 'Subscribe']) {
      assert.equal(publicConsentAction(label), 'skip', label);
    }
  });
});
