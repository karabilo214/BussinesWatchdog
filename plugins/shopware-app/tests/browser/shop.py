"""Shop side of the Shopware browser check e2e (ADR 0055): a storefront domain the worker reaches, a test customer, a
sold-out product, and counters of orders and guest customers (database) and the test customer's cart (Store API)."""

import json
import os
import subprocess
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402

TEST_CUSTOMER = {'email': 'watchdog-test-customer@example.com', 'password': 'Watchdog-test-42'}


def storefront(admin):
    return next(c for c in s.call('POST', '/api/search/sales-channel', admin, {'associations': {'domains': {}}, 'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})['json']['data'])


def setup(domain):
    admin = s.admin_token()
    channel = storefront(admin)
    template = channel['domains'][0]

    if not any(d['url'] == domain for d in channel['domains']):
        created = s.call('POST', '/api/sales-channel-domain', admin, {'url': domain, 'salesChannelId': channel['id'], 'languageId': template['languageId'], 'currencyId': template['currencyId'], 'snippetSetId': template['snippetSetId']})
        assert created['status'] == 204, created

    store = {'sw-access-key': channel['accessKey']}
    products = s.call('POST', '/store-api/product', None, {'limit': 50}, store)['json']['elements']
    available = [p for p in products if not p.get('childCount') and p.get('available') and (p.get('calculatedPrice') or {}).get('unitPrice', 0) > 1]
    product, sold_out = available[0]['id'], available[1]['id']
    assert s.call('PATCH', f'/api/product/{sold_out}', admin, {'stock': 0, 'isCloseout': True})['status'] == 204

    salutation = s.call('POST', '/store-api/salutation', None, {}, store)['json']['elements'][0]['id']
    country = next(c['id'] for c in s.call('POST', '/store-api/country', None, {'limit': 100}, store)['json']['elements'] if c['iso'] == 'DE')
    registered = s.call('POST', '/store-api/account/register', None, {
        'storefrontUrl': template['url'], 'salutationId': salutation, 'firstName': 'Test', 'lastName': 'Customer',
        'email': TEST_CUSTOMER['email'], 'password': TEST_CUSTOMER['password'], 'acceptedDataProtection': True,
        'billingAddress': {'street': 'Teststraße 2', 'zipcode': '10115', 'city': 'Berlin', 'countryId': country},
    }, store)
    assert registered['status'] == 200, registered['json']

    return {'product': product, 'sold_out': sold_out, **TEST_CUSTOMER}


def sql(query):
    result = subprocess.run(['docker', 'exec', 'bw-shopware', 'mysql', '-h127.0.0.1', '-uroot', '-proot', 'shopware', '-N', '-e', query], capture_output=True, text=True, check=True)

    return result.stdout.strip()


def test_customer_cart_lines():
    channel = storefront(s.admin_token())
    store = {'sw-access-key': channel['accessKey']}
    login = s.call('POST', '/store-api/account/login', None, {'username': TEST_CUSTOMER['email'], 'password': TEST_CUSTOMER['password']}, store)
    token = login['headers'].get('sw-context-token') or login['json']['contextToken']
    cart = s.call('GET', '/store-api/checkout/cart', None, None, {**store, 'sw-context-token': token})['json']

    return sum(item['quantity'] for item in cart['lineItems'])


def counts():
    return {
        'orders': int(sql('SELECT COUNT(*) FROM `order`')),
        'guests': int(sql('SELECT COUNT(*) FROM customer WHERE guest = 1')),
        'test_customer_cart_lines': test_customer_cart_lines(),
    }


if __name__ == '__main__':
    result = setup(sys.argv[2]) if sys.argv[1] == 'setup' else counts()
    print('BW_STORE=' + json.dumps(result))
