import sys, uuid
sys.path.insert(0, '.')
import shopware_spike as s

admin = s.admin_token()
read_keys, write_keys = s.setup_integration(admin)
read, _ = s.integration_token(read_keys)
write, _ = s.integration_token(write_keys)
order = s.call('POST', '/api/search/order', admin, {'limit': 1, 'associations': {'transactions': {}}})['json']['data'][0]
before = s.call('GET', f"/api/order/{order['id']}", admin)['json']['data']['updatedAt']
for label, token in [('read key', read), ('write key', write)]:
    r = s.call('POST', '/api/_action/sync', token, [{'action': 'upsert', 'entity': 'order', 'payload': [{'id': order['id'], 'currencyFactor': 'not-a-number'}]}])
    s.out(f'{label}: sync upsert order with an impossible value', s.error_code(r))
    r = s.call('PATCH', f"/api/order/{order['id']}", token, {'currencyFactor': 'not-a-number'})
    s.out(f'{label}: PATCH order with an impossible value', s.error_code(r))
after = s.call('GET', f"/api/order/{order['id']}", admin)['json']['data']['updatedAt']
s.out('order untouched (updatedAt before/after)', [before, after])
