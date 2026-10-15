"""Alter only the local Shopware fixture after a real PayPal Sandbox capture.

The provider-paid case simulates a store state update that was lost. The reverse case
simulates a merchant incorrectly marking an unhandled PayPal order paid.
"""
import json
import re
import subprocess
import sys

sys.path.insert(0, '../spike')
import shopware_spike as s  # noqa: E402

action, order_id = sys.argv[1:3]
admin = s.admin_token()
order = s.call('POST', '/api/search/order', admin, {'ids': [order_id], 'associations': {'transactions': {'associations': {'stateMachineState': {}}}}})['json']['data'][0]
transaction = order['transactions'][0]
assert re.fullmatch(r'[0-9a-f]{32}', order_id) and re.fullmatch(r'[0-9a-f]{32}', transaction['id'])

if action == 'provider_paid_store_unpaid':
    statement = (
        "UPDATE order_transaction SET state_id=(SELECT sms.id FROM state_machine_state sms "
        "JOIN state_machine sm ON sm.id=sms.state_machine_id WHERE sm.technical_name='order_transaction.state' "
        "AND sms.technical_name='open' LIMIT 1), updated_at=NOW(3) "
        f"WHERE id=UNHEX('{transaction['id']}'); "
        f"UPDATE `order` SET updated_at=NOW(3) WHERE id=UNHEX('{order_id}');"
    )
    subprocess.run(['docker', 'exec', 'bw-shopware', 'mysql', '-h127.0.0.1', '-uroot', '-proot', 'shopware', '-e', statement], check=True, capture_output=True)
elif action == 'store_paid_provider_unpaid':
    custom = transaction.get('customFields') or {}
    patched = s.call('PATCH', f"/api/order-transaction/{transaction['id']}", admin, {'customFields': {**custom, 'swag_paypal_is_sandbox': True}})
    assert patched['status'] == 204, s.error_code(patched)
    response = s.call('POST', f"/api/_action/order_transaction/{transaction['id']}/state/paid", admin, {})
    assert response['status'] == 200, s.error_code(response)
else:
    raise ValueError('unknown_divergence')

print('BW_DIVERGENCE=' + json.dumps({'order_number': order['orderNumber'], 'mode': action}))
