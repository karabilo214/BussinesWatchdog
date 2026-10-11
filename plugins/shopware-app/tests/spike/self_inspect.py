import json, sys, uuid
sys.path.insert(0, '.')
import shopware_spike as s

s.READ_PRIVILEGES += ['integration:read', 'acl_role:read']
admin = s.admin_token()
keys, write_keys = s.setup_integration(admin)
token, _ = s.integration_token(keys)
mine = s.call('POST', '/api/search/integration', token, {'filter': [{'type': 'equals', 'field': 'accessKey', 'value': keys['accessKey']}], 'associations': {'aclRoles': {}}})
s.out('own integration readable', [mine['status'], s.error_code(mine) if mine['status'] >= 400 else len(mine['json']['data'])])
if mine['status'] == 200:
    row = mine['json']['data'][0]
    s.out('own integration fields', sorted(row.keys()))
    s.out('secret visible', 'secretAccessKey' in row and row['secretAccessKey'] is not None)
    s.out('own privileges', sorted({p for role in row['aclRoles'] for p in role['privileges']}))
    s.out('admin flag', row.get('admin'))
others = s.call('POST', '/api/search/integration', token, {'associations': {'aclRoles': {}}})
s.out('other integrations visible (labels)', [r['label'] for r in others['json']['data']] if others['status'] == 200 else s.error_code(others))
s.out('any secret in the listing', 'secretAccessKey' in json.dumps(others['json']) and any(r.get('secretAccessKey') for r in others['json']['data']))
users = s.call('POST', '/api/search/user', token, {'limit': 1})
s.out('admin users readable', s.error_code(users) if users['status'] >= 400 else 'yes')
