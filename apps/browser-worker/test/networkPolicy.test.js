import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createNetworkPolicy, isPaymentProviderScript } from '../src/networkPolicy.js';

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

test('PayPal Payments button lookups are allowed, its order and cart changes are not', () => {
  assert.equal(decide('POST', `${store}/?wc-ajax=ppc-sdk-v6-client-token`, '{}').allow, true);
  assert.equal(decide('POST', `${store}/?wc-ajax=ppc-sdk-v6-simulate-cart`, '{"products":[]}').allow, true);

  for (const action of ['ppc-create-order', 'ppc-approve-order', 'ppc-change-cart']) {
    assert.equal(decide('POST', `${store}/?wc-ajax=${action}`, '{}').allow, false, action);
  }
});

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

const shopware = (checkoutAccess) => createNetworkPolicy({
  allowedOrigins: ['https://shop.example.test', 'https://www.paypal.com'],
  storeOrigin: 'https://shop.example.test',
  platform: 'shopware',
  checkoutAccess,
});
const lineItem = '2a88d9b59d474c7e869d8071649be43c';

test('shopware: cart operations are allowed, placing or paying an order never is', () => {
  for (const access of ['stop_before_sign_in', 'test_customer', 'guest']) {
    const sw = shopware(access);
    assert.equal(sw.decide({ method: 'POST', url: `${store}/checkout/line-item/add`, postData: multipart([[`lineItems[${lineItem}][quantity]`, '1']]) }).allow, true);
    assert.equal(sw.decide({ method: 'POST', url: `${store}/en/checkout/line-item/delete/${lineItem}`, postData: 'redirectTo=frontend.checkout.cart.page' }).allow, true);
    assert.equal(sw.decide({ method: 'POST', url: `${store}/checkout/configure`, postData: 'paymentMethodId=x' }).allow, true);
    assert.equal(sw.decide({ method: 'GET', url: `${store}/checkout/confirm` }).allow, true);
    assert.equal(sw.decide({ method: 'POST', url: `${store}/country/country-state-data`, postData: 'countryId=x' }).allow, true);

    const blocked = [
      [sw.decide({ method: 'POST', url: `${store}/checkout/order`, postData: 'tos=on&hash=x' }), 'place_order'],
      [sw.decide({ method: 'POST', url: `${store}/de/checkout/order/`, postData: 'tos=on' }), 'place_order'],
      [sw.decide({ method: 'POST', url: `${store}/store-api/checkout/order`, postData: '{}' }), 'place_order'],
      [sw.decide({ method: 'POST', url: `${store}/store-api/handle-payment`, postData: '{}' }), 'payment_capture'],
      [sw.decide({ method: 'POST', url: `${store}/account/order/update/x`, postData: 'paymentMethodId=y' }), 'order_pay_submit'],
      [sw.decide({ method: 'GET', url: `${store}/payment/finalize-transaction?_sw_payment_token=t` }), 'payment_capture'],
      [sw.decide({ method: 'POST', url: 'https://www.paypal.com/v2/checkout/orders/1/capture', postData: '{}' }), 'payment_capture'],
    ];

    for (const [decision, operation] of blocked) {
      assert.equal(decision.allow, false, `${access} ${operation}`);
      assert.equal(decision.operation, operation);
      assert.equal(sw.isPaymentOperation(operation), true);
    }

    assert.equal(sw.decide({ method: 'POST', url: `${store}/checkout/promotion/add`, postData: 'code=x' }).operation, 'unknown_mutation');
    assert.equal(sw.decide({ method: 'POST', url: `${store}/checkout/line-item/change-quantity/${lineItem}`, postData: 'quantity=9' }).operation, 'unknown_mutation');
  }
});

test('shopware: signing in only for the test customer, registering only as a guest', () => {
  const login = (access) => shopware(access).decide({ method: 'POST', url: `${store}/account/login`, postData: 'username=a%40b.c&password=x' });
  assert.equal(login('test_customer').allow, true);
  assert.equal(login('guest').operation, 'unknown_mutation');
  assert.equal(login('stop_before_sign_in').operation, 'unknown_mutation');

  const register = (access, extra = []) => shopware(access).decide({ method: 'POST', url: `${store}/account/register`, postData: new URLSearchParams([['email', 'watchdog-check@example.com'], ['billingAddress[street]', 'Teststrasse 1'], ...extra]).toString() });
  assert.equal(register('guest').allow, true);
  assert.equal(register('guest', [['createCustomerAccount', 'true']]).operation, 'customer_account_creation');
  assert.equal(register('guest', [['password', 'secret']]).operation, 'customer_account_creation');
  assert.equal(register('test_customer').operation, 'unknown_mutation');
  assert.equal(register('stop_before_sign_in').operation, 'unknown_mutation');
});

test('quiet paths on the store are dropped without counting as blocked traffic', () => {
  const sw = createNetworkPolicy({ allowedOrigins: [store], storeOrigin: store, platform: 'shopware', checkoutAccess: 'guest', quietPaths: ['/storefront/script/bw-observe'] });
  assert.deepEqual(sw.decide({ method: 'GET', url: `${store}/storefront/script/bw-observe?page=confirm&stages=rendered` }), { allow: false, reason: 'quiet' });
  assert.deepEqual(sw.decide({ method: 'POST', url: `${store}/de/storefront/script/bw-observe`, postData: 'page=confirm' }), { allow: false, reason: 'quiet' });
  assert.deepEqual(sw.decide({ method: 'GET', url: `${store}/storefront/script/bw-observe?config=1` }), { allow: true }, 'reading the store setting counts nothing');
  assert.deepEqual(sw.decide({ method: 'POST', url: `${store}/storefront/script/bw-observe?config=1`, postData: 'page=confirm' }), { allow: false, reason: 'quiet' });
  assert.equal(sw.decide({ method: 'GET', url: 'https://other.example/storefront/script/bw-observe' }).reason, 'origin_not_allowed');
  assert.equal(sw.decide({ method: 'GET', url: `${store}/storefront/script/other` }).allow, true);
  assert.equal(sw.decide({ method: 'GET', url: `${store}/checkout/confirm` }).allow, true);

  const woo = createNetworkPolicy({ allowedOrigins: [store], storeOrigin: store, quietPaths: ['/business-watchdog/v1/observe', '/business-watchdog/v1/observe/noscript'] });
  assert.deepEqual(woo.decide({ method: 'POST', url: `${store}/wp-json/business-watchdog/v1/observe`, postData: '{"stage":"rendered"}' }), { allow: false, reason: 'quiet' });
  assert.deepEqual(woo.decide({ method: 'POST', url: `${store}/?rest_route=/business-watchdog/v1/observe`, postData: '{}' }), { allow: false, reason: 'quiet' });
  assert.deepEqual(woo.decide({ method: 'GET', url: `${store}/wp-json/business-watchdog/v1/observe/noscript` }), { allow: false, reason: 'quiet' });
  assert.equal(woo.decide({ method: 'POST', url: `${store}/wp-json/business-watchdog/v1/other`, postData: '{}' }).allow, false);
});

test('public storefront checks allow store cart-add and third-party subrequests without allowing checkout/payment', () => {
  const wwwStore = 'https://www.shop.example.test';
  const pub = createNetworkPolicy({ allowedOrigins: [store, wwwStore], storeOrigin: store, platform: 'public' });

  assert.equal(pub.decide({ method: 'GET', url: `${store}/` }).allow, true);
  assert.equal(pub.decide({ method: 'GET', url: `${wwwStore}/` }).allow, true);
  assert.equal(pub.party(`${wwwStore}/product/example`), 'first');
  assert.equal(pub.decide({ method: 'GET', url: `${store}/checkout` }).allow, true);
  assert.equal(pub.decide({ method: 'GET', url: 'https://cdn.example.test/app.css' }).allow, true);
  assert.equal(pub.decide({ method: 'GET', url: 'https://cdn.example.test/', isNavigation: true }).allow, false);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/?wc-ajax=add_to_cart`, postData: 'product_id=10' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${wwwStore}/?wc-ajax=add_to_cart`, postData: 'product_id=10' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/product/x/`, postData: 'quantity=1&add-to-cart=10' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/wp-json/wc/store/v1/cart/add-item`, postData: '{"id":10}' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/wp-json/wc/store/v1/batch`, postData: JSON.stringify({ requests: [{ path: '/wc/store/v1/cart', method: 'GET' }, { path: '/wc/store/v1/products', method: 'GET' }] }) }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/wp-json/wc/store/v1/batch`, postData: JSON.stringify({ requests: [{ path: '/wc/store/v1/cart/add-item', method: 'POST' }] }) }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/wp-json/wc/store/v1/batch`, postData: JSON.stringify({ requests: [{ path: '/wc/store/v1/cart', method: 'GET' }, { path: '/wc/store/v1/checkout', method: 'POST' }] }) }).allow, false);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/checkout/line-item/add`, postData: multipart([[`lineItems[${lineItem}][quantity]`, '1']]) }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/cdn-cgi/rum`, postData: '{}' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/cdn-cgi/challenge-platform/h/g/jsd/oneshot/x`, postData: '{}' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/cookie-consent/accept`, postData: 'groups=all' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: `${store}/cookie-consent/allow-all`, postData: '' }).allow, true);
  assert.deepEqual(pub.decide({ method: 'POST', url: `${store}/?wc-ajax=update_order_review`, postData: 'security=x' }), { allow: false, reason: 'mutation', operation: 'forbidden_mutation' });
  assert.deepEqual(pub.decide({ method: 'POST', url: `${store}/checkout/order`, postData: 'tos=on' }), { allow: false, reason: 'mutation', operation: 'forbidden_mutation' });
  assert.deepEqual(pub.decide({ method: 'POST', url: `${store}/account/login`, postData: 'username=a&password=b' }), { allow: false, reason: 'mutation', operation: 'forbidden_mutation' });
  assert.equal(pub.decide({ method: 'POST', url: 'https://www.google-analytics.com/g/collect', postData: '{}' }).allow, true);
  assert.equal(pub.decide({ method: 'POST', url: 'https://cdn.example.test/api', postData: '{}' }).allow, true);
  assert.deepEqual(pub.decide({ method: 'GET', url: 'https://www.paypal.com/v2/checkout/orders/1/capture' }), { allow: false, reason: 'mutation', operation: 'payment_capture' });
  assert.deepEqual(pub.decide({ method: 'POST', url: 'https://api.stripe.com/v1/payment_intents/pi_1/confirm', postData: '{}' }), { allow: false, reason: 'mutation', operation: 'payment_capture' });
});

test('payment provider scripts are recognised by host, other third-party scripts are not', () => {
  for (const url of ['https://js.stripe.com/v3/', 'https://m.stripe.network/inner.html', 'https://www.paypal.com/sdk/js?client-id=x', 'https://www.paypalobjects.com/api/checkout.js', 'https://js.braintreegateway.com/web/3/js/client.min.js', 'https://x.klarnacdn.net/kp/lib/v1/api.js', 'https://pay.google.com/gp/p/js/pay.js']) {
    assert.equal(isPaymentProviderScript(url), true, url);
  }
  for (const url of ['https://www.googletagmanager.com/gtag/js', 'https://notstripe.com/v3/', 'https://stripe.com.evil.test/x.js', 'not a url']) {
    assert.equal(isPaymentProviderScript(url), false, url);
  }
});

test('with payment providers allowed, provider hosts are readable and every write to them stays blocked', () => {
  const store = 'https://shop.example';
  const allowed = createNetworkPolicy({ allowedOrigins: [store], storeOrigin: store, paymentProviders: true });
  for (const url of ['https://js.stripe.com/v3/', 'https://api.stripe.com/v1/elements/sessions?key=pk_test', 'https://b.stripecdn.com/x.js', 'https://www.paypal.com/sdk/js?client-id=x']) {
    assert.deepEqual(allowed.decide({ url, method: 'GET' }), { allow: true }, url);
  }
  for (const url of ['https://api.stripe.com/v1/payment_methods', 'https://api.stripe.com/v1/payment_intents/pi_1/confirm', 'https://r.stripe.com/b', 'https://m.stripe.com/6', 'https://api-m.paypal.com/v2/checkout/orders']) {
    assert.deepEqual(allowed.decide({ url, method: 'POST', postData: 'a=1' }), { allow: false, reason: 'provider_write' }, url);
  }
  assert.equal(allowed.decide({ url: 'https://stripe.com.evil.test/v3/', method: 'GET' }).allow, false);
  assert.equal(allowed.decide({ url: 'http://js.stripe.com/v3/', method: 'GET' }).allow, false);

  const closed = createNetworkPolicy({ allowedOrigins: [store], storeOrigin: store });
  assert.deepEqual(closed.decide({ url: 'https://js.stripe.com/v3/', method: 'GET' }), { allow: false, reason: 'origin_not_allowed' });
});

