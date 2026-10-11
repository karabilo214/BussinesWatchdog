"""Configures SwagPayPal in the test Shopware with the owner's PayPal sandbox shop app (ADR 0025, spike part 3).
Credentials come from the environment and are only sent to the local Shopware; nothing is printed."""

import os
import sys
import uuid

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402

HOST = os.environ.get('BW_SW_HOST', 'shop-sw.example.com')


def main():
    admin = s.admin_token()
    config = {
        'SwagPayPal.settings.sandbox': True,
        'SwagPayPal.settings.clientIdSandbox': os.environ['BW_PAYPAL_CLIENT_ID'],
        'SwagPayPal.settings.clientSecretSandbox': os.environ['BW_PAYPAL_CLIENT_SECRET'],
        'SwagPayPal.settings.intent': 'CAPTURE',
        'SwagPayPal.settings.landingPage': 'LOGIN',
    }
    saved = s.call('POST', '/api/_action/system-config/batch', admin, {'null': config})
    validated = s.call('POST', '/api/_action/paypal/validate-api-credentials', admin, {'clientId': config['SwagPayPal.settings.clientIdSandbox'], 'clientSecret': config['SwagPayPal.settings.clientSecretSandbox'], 'sandboxActive': True})
    channel = next(c for c in s.call('POST', '/api/search/sales-channel', admin, {'associations': {'domains': {}, 'paymentMethods': {}}, 'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})['json']['data'])
    method = s.call('POST', '/api/search/payment-method', admin, {'filter': [{'type': 'equals', 'field': 'technicalName', 'value': 'swag_paypal_paypal'}]})['json']['data'][0]
    s.call('PATCH', f"/api/payment-method/{method['id']}", admin, {'active': True})
    linked = s.call('POST', f"/api/sales-channel/{channel['id']}/payment-methods", admin, {'id': method['id']})
    domains = [d['url'] for d in channel['domains']]
    if f'http://{HOST}' not in domains:
        template = channel['domains'][0]
        s.call('POST', '/api/sales-channel-domain', admin, {'id': uuid.uuid4().hex, 'salesChannelId': channel['id'], 'url': f'http://{HOST}', 'languageId': template['languageId'], 'currencyId': template['currencyId'], 'snippetSetId': template['snippetSetId']})
    print('config saved', saved['status'], '| credentials valid', validated['status'], (validated['json'] or {}).get('credentialsValid'), '| method linked', linked['status'], '| method', method['technicalName'])


main()
