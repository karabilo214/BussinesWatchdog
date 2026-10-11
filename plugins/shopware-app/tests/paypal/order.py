"""Places a guest order with SwagPayPal through the Store API on the shop's own host and asks Shopware for the PayPal
approval link (handle-payment), as the storefront does. Prints the order id, number and the approval URL."""

import json
import os
import sys
import uuid

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402

HOST = os.environ.get('BW_SW_HOST', 'shop-sw.example.com')
_call = s.call


def call(method, path, token=None, body=None, headers=None):
    headers = dict(headers or {})
    if path.startswith('/store-api'):
        headers['Host'] = HOST
    return _call(method, path, token, body, headers)


s.call = call


def main():
    admin = s.admin_token()
    channel = next(c for c in s.call('POST', '/api/search/sales-channel', admin, {'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})['json']['data'])
    headers = {'sw-access-key': channel['accessKey']}
    products = s.call('POST', '/store-api/product', None, {'limit': 50}, headers)['json']['elements']
    product = next(p['id'] for p in products if not p.get('childCount') and p.get('available') and (p.get('calculatedPrice') or {}).get('unitPrice', 0) > 1)
    salutation = s.call('POST', '/store-api/salutation', None, {}, headers)['json']['elements'][0]['id']
    country = next(c['id'] for c in s.call('POST', '/store-api/country', None, {'limit': 100}, headers)['json']['elements'] if c['iso'] == 'DE')
    payment = next(p['id'] for p in s.call('POST', '/store-api/payment-method', None, {'onlyAvailable': True}, headers)['json']['elements'] if p['technicalName'] == 'swag_paypal_paypal')
    context = s.call('GET', '/store-api/context', None, None, headers)
    headers['sw-context-token'] = context['headers'].get('sw-context-token') or context['json']['token']
    registered = s.call('POST', '/store-api/account/register', None, {
        'guest': True, 'email': f'buyer-{uuid.uuid4().hex[:6]}@example.test', 'firstName': 'Sandbox', 'lastName': 'Buyer', 'salutationId': salutation,
        'acceptedDataProtection': True, 'storefrontUrl': f'http://{HOST}',
        'billingAddress': {'street': 'Teststraße 1', 'zipcode': '10115', 'city': 'Berlin', 'countryId': country},
    }, headers)
    assert registered['status'] == 200, s.error_code(registered)
    headers['sw-context-token'] = registered['headers'].get('sw-context-token', headers['sw-context-token'])
    currency = os.environ.get('BW_SW_CURRENCY', 'EUR')
    currency_id = next(c['id'] for c in s.call('POST', '/api/search/currency', admin, {'limit': 50})['json']['data'] if c['isoCode'] == currency)
    s.call('PATCH', '/store-api/context', None, {'paymentMethodId': payment, 'currencyId': currency_id}, headers)
    s.call('POST', '/store-api/checkout/cart/line-item', None, {'items': [{'type': 'product', 'referencedId': product, 'quantity': 1}]}, headers)
    order = s.call('POST', '/store-api/checkout/order', None, {}, headers)
    assert order['status'] == 200, s.error_code(order)
    order = order['json']
    finish = f'http://{HOST}/checkout/finish?orderId={order["id"]}'
    handled = s.call('POST', '/store-api/handle-payment', None, {'orderId': order['id'], 'finishUrl': finish, 'errorUrl': f'http://{HOST}/account/order/edit/{order["id"]}'}, headers)
    print('BW_ORDER=' + json.dumps({'id': order['id'], 'number': order['orderNumber'], 'total': order['amountTotal'], 'currency': currency, 'status': handled['status'], 'redirect': (handled['json'] or {}).get('redirectUrl'), 'error': None if handled['status'] == 200 else s.error_code(handled)}))


main()
