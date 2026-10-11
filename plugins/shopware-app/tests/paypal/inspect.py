"""Shows what SwagPayPal recorded on the order transaction (ids only; no personal data)."""

import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402

order_id = json.load(open(os.path.join(os.path.dirname(__file__), '.order.json')))['id']
admin = s.admin_token()
order = s.call('POST', '/api/search/order', admin, {'ids': [order_id], 'associations': {'stateMachineState': {}, 'transactions': {'associations': {'stateMachineState': {}, 'paymentMethod': {}, 'captures': {'associations': {'refunds': {}}}}}}})['json']['data'][0]
print('order', order['orderNumber'], order['amountTotal'], 'state', order['stateMachineState']['technicalName'], 'updatedAt', order['updatedAt'])
for t in order['transactions']:
    custom = {k: (v if isinstance(v, bool) or k.endswith(('_id', 'resource_id')) else type(v).__name__) for k, v in (t.get('customFields') or {}).items()}
    print('transaction', t['paymentMethod']['technicalName'], t['stateMachineState']['technicalName'], 'updatedAt', t['updatedAt'], 'customFields', json.dumps(custom), 'captures', len(t.get('captures') or []))
