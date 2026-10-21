"""Shop side of the app e2e: upload, install and activate the app archive as the owner does in Extensions, place an
order, pay it in the admin, uninstall the app. Shopware's own message worker delivers the webhooks."""

import json
import os
import subprocess
import sys
import uuid
import urllib.request

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'spike'))

import shopware_spike as s  # noqa: E402


def consume(seconds=25):
    subprocess.run(['docker', 'exec', 'bw-shopware', 'bash', '-lc', f'cd /var/www/html && php bin/console messenger:consume async --time-limit={seconds} -q'], check=False)


def upload(path):
    admin = s.admin_token()
    boundary = uuid.uuid4().hex
    data = open(path, 'rb').read()
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="BusinessWatchdog.zip"\r\nContent-Type: application/zip\r\n\r\n').encode() + data + f'\r\n--{boundary}--\r\n'.encode()
    request = urllib.request.Request(s.BASE + '/api/_action/extension/upload', data=body, method='POST', headers={'Authorization': 'Bearer ' + admin, 'Content-Type': f'multipart/form-data; boundary={boundary}'})
    try:
        uploaded = urllib.request.urlopen(request, timeout=120).status
    except urllib.error.HTTPError as error:
        uploaded = f'{error.code}:{error.read()[:200]!r}'
    installed = s.call('POST', '/api/_action/extension/install/app/BusinessWatchdog', admin, {})
    activated = s.call('PUT', '/api/_action/extension/activate/app/BusinessWatchdog', admin, {})
    consume()
    apps = s.call('POST', '/api/search/app', admin, {'filter': [{'type': 'equals', 'field': 'name', 'value': 'BusinessWatchdog'}], 'associations': {'aclRole': {}}})['json']['data']
    detail = None if installed['status'] < 300 else ((installed['json'] or {}).get('errors') or [{}])[0].get('detail', '')[:300]
    return {'upload': uploaded, 'install': installed['status'], 'install_error': detail, 'activate': activated['status'], 'active': bool(apps and apps[0]['active']), 'privileges': sorted(apps[0]['aclRole']['privileges']) if apps else []}


def update(path):
    admin = s.admin_token()
    boundary = uuid.uuid4().hex
    data = open(path, 'rb').read()
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="BusinessWatchdog.zip"\r\nContent-Type: application/zip\r\n\r\n').encode() + data + f'\r\n--{boundary}--\r\n'.encode()
    request = urllib.request.Request(s.BASE + '/api/_action/extension/upload', data=body, method='POST', headers={'Authorization': 'Bearer ' + admin, 'Content-Type': f'multipart/form-data; boundary={boundary}'})
    try:
        uploaded = urllib.request.urlopen(request, timeout=120).status
    except urllib.error.HTTPError as error:
        uploaded = f'{error.code}:{error.read()[:200]!r}'
    updated = s.call('POST', '/api/_action/extension/update/app/BusinessWatchdog', admin, {'allowNewPermissions': True})
    consume()
    apps = s.call('POST', '/api/search/app', admin, {'filter': [{'type': 'equals', 'field': 'name', 'value': 'BusinessWatchdog'}]})['json']['data']
    detail = None if updated['status'] < 300 else ((updated['json'] or {}).get('errors') or [{}])[0].get('detail', '')[:300]
    return {'upload': uploaded, 'update': updated['status'], 'update_error': detail, 'version': apps[0]['version'] if apps else None, 'active': bool(apps and apps[0]['active'])}


def order():
    admin = s.admin_token()
    channel = next(c for c in s.call('POST', '/api/search/sales-channel', admin, {'associations': {'domains': {}}, 'filter': [{'type': 'equals', 'field': 'typeId', 'value': '8a243080f92e4c719546314b577cf82b'}]})['json']['data'])
    sales_channel = {'accessKey': channel['accessKey'], 'url': channel['domains'][0]['url']}
    store = {'sw-access-key': sales_channel['accessKey']}
    products = s.call('POST', '/store-api/product', None, {'limit': 50}, store)['json']['elements']
    product = next(p['id'] for p in products if not p.get('childCount') and p.get('available') and (p.get('calculatedPrice') or {}).get('unitPrice', 0) > 1)
    salutation = s.call('POST', '/store-api/salutation', None, {}, store)['json']['elements'][0]['id']
    country = next(c['id'] for c in s.call('POST', '/store-api/country', None, {'limit': 100}, store)['json']['elements'] if c['iso'] == 'DE')
    payment = next(p['id'] for p in s.call('POST', '/store-api/payment-method', None, {'onlyAvailable': True}, store)['json']['elements'] if p['technicalName'] == 'payment_prepayment')
    placed = s.place_order(admin, sales_channel, product, salutation, country, payment, 0)
    consume()
    return {'id': placed['id'], 'number': placed['orderNumber'], 'transaction': placed['transactions'][0]['id']}


def pay(transaction):
    status = s.call('POST', f'/api/_action/order_transaction/{transaction}/state/paid', s.admin_token(), {})['status']
    consume()
    return {'paid': status}


def uninstall():
    admin = s.admin_token()
    status = s.call('POST', '/api/_action/extension/uninstall/app/BusinessWatchdog', admin, {'keepUserData': False})['status']
    consume()
    return {'uninstall': status}


def module():
    """The app's main module as the Administration opens it: Shopware signs its source with the shop secret."""
    admin = s.admin_token()
    modules = s.call('GET', '/api/app-system/modules', admin)
    apps = (modules['json'] or {}).get('modules', []) if isinstance(modules['json'], dict) else []
    app = next((a for a in apps if a.get('name') == 'BusinessWatchdog'), None)
    return {'status': modules['status'], 'main_module': (app or {}).get('mainModule'), 'raw': None if app else str(modules['json'])[:400]}


if __name__ == '__main__':
    step = sys.argv[1]
    result = {'upload': lambda: upload(sys.argv[2]), 'update': lambda: update(sys.argv[2]), 'order': order, 'pay': lambda: pay(sys.argv[2]), 'uninstall': uninstall, 'module': module}[step]()
    print('BW_SHOP=' + json.dumps(result))
