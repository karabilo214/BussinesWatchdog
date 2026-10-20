"""Configures SwagPayPal in the Shopware test store for PayPal Sandbox and makes PayPal available in the storefront
sales channel. Run on the server: the admin password comes from the environment (test-shops.env), the sandbox shop
credentials from the variables BW_PAYPAL_ID / BW_PAYPAL_SECRET; nothing secret is printed."""

import json
import os
import urllib.request

BASE = os.environ['SW_BASE']


def call(method, path, token=None, body=None):
    request = urllib.request.Request(BASE + path, data=None if body is None else json.dumps(body).encode(), method=method)
    request.add_header('Content-Type', 'application/json')
    request.add_header('Accept', 'application/json')
    if token:
        request.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            raw = response.read()
            return response.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as error:
        raw = error.read()
        return error.code, (json.loads(raw) if raw else None)


status, token = call('POST', '/api/oauth/token', body={'grant_type': 'password', 'client_id': 'administration', 'scope': 'write user-verified', 'username': 'admin', 'password': os.environ['SW_ADMIN_PASSWORD']})
assert status == 200, status
token = token['access_token']

config = {
    'SwagPayPal.settings.sandbox': True,
    'SwagPayPal.settings.clientIdSandbox': os.environ['BW_PAYPAL_ID'],
    'SwagPayPal.settings.clientSecretSandbox': os.environ['BW_PAYPAL_SECRET'],
    'SwagPayPal.settings.intent': 'CAPTURE',
    'SwagPayPal.settings.landingPage': 'LOGIN',
}
saved, _ = call('POST', '/api/_action/system-config/batch', token, {'null': config})
validated, result = call('POST', '/api/_action/paypal/validate-api-credentials', token, {'clientId': config['SwagPayPal.settings.clientIdSandbox'], 'clientSecret': config['SwagPayPal.settings.clientSecretSandbox'], 'sandboxActive': True})

_, channels = call('POST', '/api/search/sales-channel', token, {'associations': {'paymentMethods': {}, 'currency': {}}, 'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})
channel = channels['data'][0]
_, methods = call('POST', '/api/search/payment-method', token, {'filter': [{'type': 'equals', 'field': 'technicalName', 'value': 'swag_paypal_paypal'}]})
method = methods['data'][0]
call('PATCH', f"/api/payment-method/{method['id']}", token, {'active': True})
linked, _ = call('POST', f"/api/sales-channel/{channel['id']}/payment-methods", token, {'id': method['id']})

_, active = call('POST', '/api/search/payment-method', token, {'filter': [{'type': 'equals', 'field': 'active', 'value': True}, {'type': 'equals', 'field': 'salesChannels.id', 'value': channel['id']}]})
print('config', saved, '| credentials', validated, (result or {}).get('credentialsValid'), '| linked', linked,
      '| channel currency', channel['currency']['isoCode'],
      '| active methods', ', '.join(sorted(m['technicalName'] for m in active['data'])))
