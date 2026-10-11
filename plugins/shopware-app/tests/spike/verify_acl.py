import json, sys, uuid
sys.path.insert(0, '.')
import shopware_spike as s

admin = s.admin_token()
keys, _ = s.setup_integration(admin)
token, _ = s.integration_token(keys)
orders = s.call('POST', '/api/search/order', admin, {'limit': 3, 'sort': [{'field': 'createdAt', 'order': 'DESC'}], 'associations': {'transactions': {'associations': {'stateMachineState': {}}}, 'stateMachineState': {}}})['json']['data']
order = next(o for o in orders if o['stateMachineState']['technicalName'] == 'open')
tx = order['transactions'][0]
s.out('before', {'order_state': order['stateMachineState']['technicalName'], 'tx_state': tx['stateMachineState']['technicalName']})
r1 = s.call('POST', f"/api/_action/order_transaction/{tx['id']}/state/paid", token, {})
r2 = s.call('POST', f"/api/_action/order/{order['id']}/state/process", token, {})
s.out('read key: transaction -> paid, order -> process', [s.error_code(r1), s.error_code(r2)])
after = s.call('POST', '/api/search/order', admin, {'ids': [order['id']], 'associations': {'transactions': {'associations': {'stateMachineState': {}}}, 'stateMachineState': {}}})['json']['data'][0]
s.out('after (read by admin)', {'order_state': after['stateMachineState']['technicalName'], 'tx_state': after['transactions'][0]['stateMachineState']['technicalName']})
tax = s.call('POST', '/api/search/tax', admin, {'limit': 1})['json']['data'][0]['id']
product = {'id': uuid.uuid4().hex, 'name': 'ACL probe', 'productNumber': 'ACL-' + uuid.uuid4().hex[:8], 'stock': 1, 'taxId': tax, 'price': [{'currencyId': 'b7d2554b0ce847cd82f3ac9bd1c0dfca', 'gross': 1, 'net': 1, 'linked': False}]}
r3 = s.call('POST', '/api/product', token, product)
s.out('read key: create a valid product', s.error_code(r3))
s.out('product exists afterwards', s.call('GET', f"/api/product/{product['id']}", admin)['status'])
missing = uuid.uuid4().hex
s.out('read key: DELETE missing order', s.error_code(s.call('DELETE', f'/api/order/{missing}', token)))
r4 = s.call('POST', '/api/_action/sync', token, [{'action': 'upsert', 'entity': 'order', 'payload': [{'id': order['id'], 'customerComment': 'sync write'}]}])
s.out('read key: _action/sync upsert order', s.error_code(r4))
r5 = s.call('POST', f"/api/_action/order/{order['id']}/document/invoice", token, {})
s.out('read key: create invoice document', s.error_code(r5))
