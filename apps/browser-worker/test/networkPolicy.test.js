import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createNetworkPolicy } from '../src/networkPolicy.js';

const policy = createNetworkPolicy({
  allowedOrigins: ['https://shop.example.test', 'https://js.stripe.com', 'https://api.stripe.com'],
  storeOrigin: 'https://shop.example.test',
});
const store = 'https://shop.example.test';

const decide = (method, url, postData = null) => policy.decide({ method, url, postData });

test('reads are allowed only on allowed https origins', () => {
  assert.equal(decide('GET', `${store}/product/x/`).allow, true);
  assert.equal(decide('GET', 'https://js.stripe.com/v3/').allow, true);
  assert.deepEqual(decide('GET', 'https://www.google-analytics.com/collect'), { allow: false, reason: 'origin_not_allowed' });
  assert.equal(decide('GET', 'http://shop.example.test/').allow, false);
  assert.equal(decide('GET', 'https://shop.example.test:8443/').allow, false);
  assert.equal(decide('GET', 'https://169.254.169.254/latest/meta-data').allow, false);
  assert.equal(decide('GET', 'file:///etc/passwd').reason, 'scheme');
  assert.equal(decide('GET', 'data:image/png;base64,AAAA').allow, true);
});

test('cart mutations are allowed', () => {
  assert.equal(decide('POST', `${store}/?wc-ajax=add_to_cart`, 'product_id=10&quantity=1').allow, true);
  assert.equal(decide('POST', `${store}/?wc-ajax=update_order_review`, 'security=x').allow, true);
  assert.equal(decide('POST', `${store}/product/x/`, 'quantity=1&add-to-cart=10').allow, true);
  assert.equal(decide('POST', `${store}/wp-json/wc/store/v1/cart/add-item`, '{"id":10}').allow, true);
  assert.equal(decide('POST', `${store}/?rest_route=/wc/store/cart/update-customer`, '{}').allow, true);
  assert.equal(decide('PUT', `${store}/wp-json/wc/store/v1/checkout`, '{}').allow, true);
  assert.equal(decide('POST', `${store}/wp-json/wc/store/v1/batch`, JSON.stringify({ requests: [{ path: '/wc/store/v1/cart/select-shipping-rate', method: 'POST' }] })).allow, true);
});

const multipart = (fields) => fields.map(([name, value]) => `------B\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n${value}\r\n`).join('') + '------B--\r\n';

test('multipart add-to-cart forms are recognised', () => {
  assert.equal(decide('POST', `${store}/?p=10`, multipart([['quantity', '1'], ['add-to-cart', '10']])).allow, true);
  assert.equal(decide('POST', `${store}/?p=10`, multipart([['add-to-cart', '10'], ['woocommerce-process-checkout-nonce', 'abc']])).operation, 'classic_checkout_submit');
  assert.equal(decide('POST', `${store}/checkout/`, multipart([['payment_method', 'bacs'], ['woocommerce-pay-nonce', 'x']])).operation, 'order_pay_submit');
  assert.equal(decide('POST', `${store}/?p=10`, multipart([['quantity', '1']])).operation, 'unknown_mutation');
});

test('placing an order is blocked whatever the endpoint', () => {
  const cases = [
    [decide('POST', `${store}/?wc-ajax=checkout`, 'billing_email=a@b.c&payment_method=bacs'), 'classic_checkout_submit'],
    [decide('POST', `${store}/checkout/`, 'payment_method=bacs&woocommerce-process-checkout-nonce=abc'), 'classic_checkout_submit'],
    [decide('POST', `${store}/product/x/`, 'add-to-cart=10&place_order=1'), 'classic_checkout_submit'],
    [decide('POST', `${store}/wp-json/wc/store/v1/checkout`, '{}'), 'store_api_checkout_submit'],
    [decide('POST', `${store}/?rest_route=/wc/store/checkout`, '{}'), 'store_api_checkout_submit'],
    [decide('POST', `${store}/wp-json/wc/store/v1/checkout/123?key=wc_order_x`, '{}'), 'order_pay_submit'],
    [decide('POST', `${store}/wp-json/wc/store/v1/batch`, JSON.stringify({ requests: [{ path: '/wc/store/v1/checkout', method: 'POST' }] })), 'store_api_checkout_submit'],
    [decide('POST', `${store}/checkout/order-pay/123/?pay_for_order=true&key=wc_order_x`, 'payment_method=bacs'), 'order_pay_submit'],
    [decide('POST', 'https://api.stripe.com/v1/payment_intents/pi_1/confirm', 'x=1'), 'payment_capture'],
    [decide('POST', 'https://api.stripe.com/v1/refunds', 'x=1'), 'payment_capture'],
  ];

  for (const [decision, operation] of cases) {
    assert.equal(decision.allow, false);
    assert.equal(decision.operation, operation);
    assert.equal(policy.isPaymentOperation(decision.operation), true);
  }
});

test('unknown store mutations are blocked but are not payment operations', () => {
  for (const decision of [
    decide('POST', `${store}/wp-admin/admin-ajax.php`, 'action=track'),
    decide('POST', `${store}/?wc-ajax=apply_coupon`, 'coupon=X'),
    decide('POST', `${store}/wp-json/wc/store/v1/batch`, 'not json'),
    decide('DELETE', `${store}/wp-json/wp/v2/posts/1`),
  ]) {
    assert.equal(decision.allow, false);
    assert.equal(decision.operation, 'unknown_mutation');
    assert.equal(policy.isPaymentOperation(decision.operation), false);
  }
});

test('local insecure mode allows http and ports for the test matrix only', () => {
  const local = createNetworkPolicy({ allowedOrigins: ['http://wordpress'], storeOrigin: 'http://wordpress', insecureLocal: true });

  assert.equal(local.decide({ method: 'GET', url: 'http://wordpress/?p=10' }).allow, true);
  assert.equal(local.decide({ method: 'POST', url: 'http://wordpress/?wc-ajax=checkout', postData: 'a=1' }).operation, 'classic_checkout_submit');
});

test('parties distinguish store, configured gateways and third parties', () => {
  assert.equal(policy.party(`${store}/cart/`), 'first');
  assert.equal(policy.party('https://js.stripe.com/v3/'), 'gateway');
  assert.equal(policy.party('https://fonts.gstatic.com/x.woff2'), 'third');
});
