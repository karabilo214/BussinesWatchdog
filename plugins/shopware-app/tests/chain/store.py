"""Store side of the Shopware chain e2e: a read-only Integration, orders through the Store API, admin changes."""

import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402


def setup():
    admin = s.admin_token()
    keys, _ = s.setup_integration(admin)
    channel = next(c for c in s.call('POST', '/api/search/sales-channel', admin, {'associations': {'domains': {}}, 'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})['json']['data'])
    sales_channel = {'accessKey': channel['accessKey'], 'url': channel['domains'][0]['url']}
    store = {'sw-access-key': sales_channel['accessKey']}
    products = s.call('POST', '/store-api/product', None, {'limit': 50}, store)['json']['elements']
    product = next(p['id'] for p in products if not p.get('childCount') and p.get('available') and (p.get('calculatedPrice') or {}).get('unitPrice', 0) > 1)
    salutation = s.call('POST', '/store-api/salutation', None, {}, store)['json']['elements'][0]['id']
    country = next(c['id'] for c in s.call('POST', '/store-api/country', None, {'limit': 100}, store)['json']['elements'] if c['iso'] == 'DE')
    payment = next(p['id'] for p in s.call('POST', '/store-api/payment-method', None, {'onlyAvailable': True}, store)['json']['elements'] if p['technicalName'] == 'payment_prepayment')
    orders = [s.place_order(admin, sales_channel, product, salutation, country, payment, i) for i in range(3)]
    return {'access_key': keys['accessKey'], 'secret': keys['secretAccessKey'], 'orders': [{'id': o['id'], 'number': o['orderNumber'], 'total': o['amountTotal'], 'transaction': o['transactions'][0]['id']} for o in orders]}


def change(orders):
    admin = s.admin_token()
    paid, refunded, doomed = orders
    out = {
        'paid': s.call('POST', f"/api/_action/order_transaction/{paid['transaction']}/state/paid", admin, {})['status'],
        'refund_paid': s.call('POST', f"/api/_action/order_transaction/{refunded['transaction']}/state/paid", admin, {})['status'],
        'refund_partially': s.call('POST', f"/api/_action/order_transaction/{refunded['transaction']}/state/refund_partially", admin, {})['status'],
        'cancel_order': s.call('POST', f"/api/_action/order/{paid['id']}/state/process", admin, {})['status'],
        'delete': s.call('DELETE', f"/api/order/{doomed['id']}", admin)['status'],
    }
    return out


if __name__ == '__main__':
    step = sys.argv[1]
    result = setup() if step == 'setup' else change(json.loads(sys.argv[2]))
    print('BW_STORE=' + json.dumps(result))
