"""Refunds a SwagPayPal capture as the shop admin does in the order's PayPal tab (amount in the order currency)."""

import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402

order_id, amount = sys.argv[1], sys.argv[2]
admin = s.admin_token()
order = s.call('POST', '/api/search/order', admin, {'ids': [order_id], 'associations': {'currency': {}, 'transactions': {}}})['json']['data'][0]
tx = next(t for t in order['transactions'] if (t.get('customFields') or {}).get('swag_paypal_resource_id'))
custom = tx['customFields']
response = s.call('POST', f"/api/_action/paypal-v2/refund-capture/{tx['id']}/{custom['swag_paypal_resource_id']}/{custom['swag_paypal_order_id']}", admin,
                  {'amount': amount, 'currency': order['currency']['isoCode'], 'invoiceNumber': '', 'noteToPayer': 'Watchdog spike refund', 'partnerAttributionId': custom.get('swag_paypal_partner_attribution_id', '')})
print('BW_REFUND=' + json.dumps({'status': response['status'], 'error': None if response['status'] < 300 else s.error_code(response)}))
