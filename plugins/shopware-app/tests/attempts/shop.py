"""Shop side of the Shopware payment attempts e2e (ADR 0056): a read-only Integration, orders through the Store API
with a chosen payment method, and payment state transitions as the payment handler or the merchant would make them."""

import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402


def context():
    admin = s.admin_token()
    channel = next(c for c in s.call('POST', '/api/search/sales-channel', admin, {'associations': {'domains': {}}, 'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})['json']['data'])
    store = {'sw-access-key': channel['accessKey']}
    products = s.call('POST', '/store-api/product', None, {'limit': 50}, store)['json']['elements']
    product = next(p['id'] for p in products if not p.get('childCount') and p.get('available') and (p.get('calculatedPrice') or {}).get('unitPrice', 0) > 1)
    salutation = s.call('POST', '/store-api/salutation', None, {}, store)['json']['elements'][0]['id']
    country = next(c['id'] for c in s.call('POST', '/store-api/country', None, {'limit': 100}, store)['json']['elements'] if c['iso'] == 'DE')
    methods = {p['technicalName']: p['id'] for p in s.call('POST', '/store-api/payment-method', None, {'onlyAvailable': True}, store)['json']['elements']}
    return admin, {'accessKey': channel['accessKey'], 'url': channel['domains'][0]['url']}, product, salutation, country, methods


def setup():
    keys, _ = s.setup_integration(s.admin_token())
    return {'access_key': keys['accessKey'], 'secret': keys['secretAccessKey']}


def orders(method, count, transition):
    admin, channel, product, salutation, country, methods = context()
    placed = []
    for i in range(int(count)):
        order = s.place_order(admin, channel, product, salutation, country, methods[method], i)
        transaction = order['transactions'][0]['id']
        if transition != 'none':
            moved = s.call('POST', f'/api/_action/order_transaction/{transaction}/state/{transition}', admin, {})
            assert moved['status'] == 200, moved['json']
        placed.append({'order': order['id'], 'transaction': transaction})
    return placed


if __name__ == '__main__':
    result = setup() if sys.argv[1] == 'setup' else orders(*sys.argv[2:5])
    print('BW_STORE=' + json.dumps(result))
