"""Shopware 6 Admin API spike (ADR 0025): an integration with a read-only ACL role on a real Shopware.

Creates the role and the integration the way a store owner would (Settings → System → Integrations), places orders
through the Store API, changes them in the admin, and checks what the integration key can read, how money and states
come back, how the order's updatedAt behaves, and that every write is refused. Prints findings only, no secrets.
"""

import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.environ.get('SW_BASE', 'http://127.0.0.1:8480')

READ_PRIVILEGES = [
    'order:read', 'order_transaction:read', 'order_transaction_capture:read', 'order_transaction_capture_refund:read',
    'order_delivery:read', 'currency:read', 'payment_method:read', 'state_machine_state:read', 'state_machine:read',
    'sales_channel:read', 'order_line_item:read', 'version:read',
]


def call(method, path, token=None, body=None, headers=None):
    data = None if body is None else json.dumps(body).encode()
    request = urllib.request.Request(BASE + path, data=data, method=method)
    request.add_header('Accept', 'application/json')
    if body is not None:
        request.add_header('Content-Type', 'application/json')
    if token:
        request.add_header('Authorization', 'Bearer ' + token)
    for key, value in (headers or {}).items():
        request.add_header(key, value)
    started = time.time()
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            raw = response.read()
            status, response_headers = response.status, dict(response.headers)
    except urllib.error.HTTPError as error:
        raw, status, response_headers = error.read(), error.code, dict(error.headers)
    payload = json.loads(raw) if raw.strip().startswith((b'{', b'[')) else None
    return {'status': status, 'json': payload, 'headers': response_headers, 'bytes': len(raw), 'ms': int((time.time() - started) * 1000)}


def out(label, value):
    print(f'{label}: {json.dumps(value, ensure_ascii=False, default=str)}')


def error_code(response):
    errors = (response['json'] or {}).get('errors') or [{}]
    return f"{response['status']}:{errors[0].get('code', '')}"


def admin_token():
    response = call('POST', '/api/oauth/token', body={'grant_type': 'password', 'client_id': 'administration', 'scope': 'write user-verified', 'username': 'admin', 'password': 'shopware'})
    return response['json']['access_token']


def setup_integration(admin):
    role_id = uuid.uuid4().hex
    created = call('POST', '/api/acl-role', admin, {'id': role_id, 'name': 'Business Watchdog (read)', 'privileges': READ_PRIVILEGES})
    assert created['status'] == 204, created
    keys = call('GET', '/api/_action/access-key/intergration', admin)['json']
    integration = call('POST', '/api/integration', admin, {'label': 'Business Watchdog', 'accessKey': keys['accessKey'], 'secretAccessKey': keys['secretAccessKey'], 'admin': False, 'aclRoles': [{'id': role_id}]})
    assert integration['status'] == 204, integration
    write_keys = call('GET', '/api/_action/access-key/intergration', admin)['json']
    write_role = uuid.uuid4().hex
    call('POST', '/api/acl-role', admin, {'id': write_role, 'name': 'Spike (write)', 'privileges': READ_PRIVILEGES + ['order:update', 'order:delete']})
    call('POST', '/api/integration', admin, {'label': 'Spike write', 'accessKey': write_keys['accessKey'], 'secretAccessKey': write_keys['secretAccessKey'], 'admin': False, 'aclRoles': [{'id': write_role}]})
    out('access key format', {'prefix': keys['accessKey'][:4], 'length': len(keys['accessKey']), 'secret_length': len(keys['secretAccessKey'])})
    return keys, write_keys


def integration_token(keys):
    response = call('POST', '/api/oauth/token', body={'grant_type': 'client_credentials', 'client_id': keys['accessKey'], 'client_secret': keys['secretAccessKey']})
    return response['json']['access_token'], response['json'].get('expires_in')


def place_order(admin, sales_channel, product_id, salutation_id, country_id, payment_method_id, index):
    headers = {'sw-access-key': sales_channel['accessKey']}
    context = call('GET', '/store-api/context', None, None, headers)
    headers['sw-context-token'] = context['headers'].get('sw-context-token') or context['json']['token']
    registered = call('POST', '/store-api/account/register', None, {
        'guest': True, 'email': f'buyer{index}-{uuid.uuid4().hex[:6]}@example.test', 'firstName': 'Private', 'lastName': 'Buyer', 'salutationId': salutation_id,
        'acceptedDataProtection': True, 'storefrontUrl': sales_channel['url'],
        'billingAddress': {'street': 'Teststraße 1', 'zipcode': '10115', 'city': 'Berlin', 'countryId': country_id},
    }, headers)
    assert registered['status'] == 200, error_code(registered)
    headers['sw-context-token'] = registered['headers'].get('sw-context-token', headers['sw-context-token'])
    call('PATCH', '/store-api/context', None, {'paymentMethodId': payment_method_id}, headers)
    call('POST', '/store-api/checkout/cart/line-item', None, {'items': [{'type': 'product', 'referencedId': product_id, 'quantity': 1 + index}]}, headers)
    order = call('POST', '/store-api/checkout/order', None, {}, headers)
    assert order['status'] == 200, error_code(order)
    return order['json']


def main():
    admin = admin_token()
    keys, write_keys = setup_integration(admin)
    token, expires = integration_token(keys)
    out('integration token lifetime seconds', expires)

    channel = next(c for c in call('POST', '/api/search/sales-channel', admin, {'associations': {'domains': {}}, 'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})['json']['data'])
    sales_channel = {'accessKey': channel['accessKey'], 'url': channel['domains'][0]['url']}
    store = {'sw-access-key': sales_channel['accessKey']}
    products = call('POST', '/store-api/product', None, {'limit': 50}, store)['json']['elements']
    product_id = next(p['id'] for p in products if not p.get('childCount') and p.get('available') and (p.get('calculatedPrice') or {}).get('unitPrice', 0) > 1)
    salutation_id = call('POST', '/store-api/salutation', None, {}, store)['json']['elements'][0]['id']
    countries = call('POST', '/store-api/country', None, {'limit': 100}, store)['json']['elements']
    country_id = next(c['id'] for c in countries if c['iso'] == 'DE')
    payments = call('POST', '/store-api/payment-method', None, {'onlyAvailable': True}, store)['json']['elements']
    out('payment methods', [(p['technicalName'], p.get('handlerIdentifier', '').split('\\')[-1]) for p in payments])
    payment_id = next(p['id'] for p in payments if 'prepayment' in p['technicalName'] or 'invoice' in p['technicalName'])

    orders = [place_order(admin, sales_channel, product_id, salutation_id, country_id, payment_id, i) for i in range(3)]
    out('placed orders', [(o['orderNumber'], o['amountTotal'], o['currency']['isoCode'] if o.get('currency') else None) for o in orders])

    paid = orders[0]
    tx = paid['transactions'][0]['id']
    stamps = lambda: (lambda o: [o['updatedAt'], o['createdAt'], [(t['updatedAt'], t['createdAt']) for t in o['transactions']]])(call('POST', '/api/search/order', token, {'ids': [paid['id']], 'associations': {'transactions': {}}})['json']['data'][0])
    before = stamps()
    time.sleep(1.2)
    out('admin transition paid', call('POST', f'/api/_action/order_transaction/{tx}/state/paid', admin, {})['status'])
    after_paid = stamps()
    time.sleep(1.2)
    out('admin transition refund_partially', call('POST', f'/api/_action/order_transaction/{tx}/state/refund_partially', admin, {})['status'])
    after_refund = stamps()
    out('order [updatedAt, createdAt, transactions] before/after paid/after refund', [before, after_paid, after_refund])
    out('admin cancel order 2', call('POST', f"/api/_action/order/{orders[1]['id']}/state/cancel", admin, {})['status'])

    listing = call('GET', '/api/order?limit=2', token)
    out('GET /api/order (list) with read role', [listing['status'], listing['json'].get('total') if listing['json'] else None, listing['headers'].get('Content-Type')])
    query = urllib.parse.urlencode({'limit': 5, 'filter[0][type]': 'range', 'filter[0][field]': 'updatedAt', 'filter[0][parameters][gte]': '2000-01-01T00:00:00+00:00', 'sort': 'updatedAt'})
    filtered = call('GET', '/api/order?' + query, token)
    out('GET /api/order with criteria in the query', [filtered['status'], error_code(filtered) if filtered['status'] >= 400 else len(filtered['json']['data'])])

    criteria = {
        'limit': 100, 'page': 1, 'total-count-mode': 1,
        'filter': [{'type': 'multi', 'operator': 'OR', 'queries': [
            {'type': 'range', 'field': 'updatedAt', 'parameters': {'gte': '2000-01-01T00:00:00+00:00'}},
            {'type': 'range', 'field': 'createdAt', 'parameters': {'gte': '2000-01-01T00:00:00+00:00'}},
            {'type': 'range', 'field': 'transactions.updatedAt', 'parameters': {'gte': '2000-01-01T00:00:00+00:00'}},
        ]}],
        'sort': [{'field': 'createdAt', 'order': 'ASC'}, {'field': 'id', 'order': 'ASC'}],
        'associations': {'currency': {}, 'stateMachineState': {}, 'transactions': {'associations': {'stateMachineState': {}, 'paymentMethod': {}, 'captures': {'associations': {'refunds': {}}}}}},
    }
    full = call('POST', '/api/search/order', token, criteria)
    out('POST /api/search/order (criteria) with read role', [full['status'], full['json'].get('total') if full['json'] else error_code(full), full['bytes'], full['ms']])
    order = next(o for o in full['json']['data'] if o['id'] == paid['id'])
    out('order keys', sorted(order.keys()))
    out('money and state', {k: order.get(k) for k in ['orderNumber', 'amountTotal', 'amountNet', 'positionPrice', 'shippingTotal', 'taxStatus', 'currencyFactor', 'orderDateTime', 'createdAt', 'updatedAt', 'versionId']})
    out('price object', order.get('price'))
    out('currency', {k: order['currency'].get(k) for k in ['isoCode', 'decimalPrecision', 'itemRounding', 'totalRounding']})
    out('order state', order['stateMachineState']['technicalName'])
    out('transactions', [{'state': t['stateMachineState']['technicalName'], 'amount': t['amount'].get('totalPrice'), 'payment': t['paymentMethod'].get('technicalName'), 'handler': t['paymentMethod'].get('handlerIdentifier', '').split('\\')[-1], 'customFields': t.get('customFields'), 'captures': t.get('captures')} for t in order['transactions']])
    out('personal data present without includes', {k: k in order and order[k] is not None for k in ['orderCustomer', 'billingAddress', 'addresses', 'deliveries', 'lineItems', 'customerComment']})

    includes = {**criteria, 'includes': {'order': ['id', 'orderNumber', 'amountTotal', 'currency', 'stateMachineState', 'transactions', 'createdAt', 'updatedAt', 'orderDateTime', 'versionId', 'customFields'],
                                          'currency': ['isoCode', 'itemRounding'], 'state_machine_state': ['technicalName'],
                                          'order_transaction': ['id', 'amount', 'stateMachineState', 'paymentMethod', 'customFields', 'captures', 'createdAt', 'updatedAt'],
                                          'payment_method': ['technicalName', 'handlerIdentifier'], 'calculated_price': ['totalPrice']}}
    slim = call('POST', '/api/search/order', token, includes)
    out('with includes: bytes full/slim', [full['bytes'], slim['bytes']])
    slim_order = slim['json']['data'][0]
    out('slim order keys', sorted(slim_order.keys()))
    out('slim payload contains a name or e-mail', any(s in json.dumps(slim['json']) for s in ['Private', '@example.test', 'Teststraße']))

    out('read customers with read role', error_code(call('POST', '/api/search/customer', token, {'limit': 1})))
    out('read order customer association', error_code(call('POST', '/api/search/order', token, {'limit': 1, 'associations': {'orderCustomer': {}}})))
    out('write PATCH order', error_code(call('PATCH', f"/api/order/{orders[2]['id']}", token, {'customerComment': 'x'})))
    out('write DELETE order', error_code(call('DELETE', f"/api/order/{orders[2]['id']}", token)))
    out('write transaction state refund', error_code(call('POST', f'/api/_action/order_transaction/{tx}/state/refund', token, {})))
    out('write order state cancel', error_code(call('POST', f"/api/_action/order/{orders[2]['id']}/state/cancel", token, {})))
    out('write create product', error_code(call('POST', '/api/product', token, {'name': 'x'})))
    after_writes = call('GET', f"/api/order/{orders[2]['id']}", token)['json']['data']
    out('order 3 unchanged after refused writes', [after_writes.get('customerComment'), after_writes.get('updatedAt')])

    missing = '0' * 31 + '1'
    write_token, _ = integration_token(write_keys)
    out('probe PATCH missing order, read key', error_code(call('PATCH', f'/api/order/{missing}', token, {})))
    out('probe PATCH missing order, write key', error_code(call('PATCH', f'/api/order/{missing}', write_token, {})))

    out('admin delete order 3', call('DELETE', f"/api/order/{orders[2]['id']}", admin)['status'])
    out('deleted order read', error_code(call('GET', f"/api/order/{orders[2]['id']}", token)))
    ids = call('POST', '/api/search-ids/order', token, {'ids': [o['id'] for o in orders]})
    out('search-ids for the three orders', [ids['status'], ids['json'].get('total') if ids['json'] else None])
    out('integration info', call('GET', '/api/_info/version', token)['json'])


if __name__ == '__main__':
    sys.exit(main())
