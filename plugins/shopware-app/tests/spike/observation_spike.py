"""Spike for ADR 0062: can a Shopware app (no PHP) collect checkout stages on the shop's own domain? Installs
observation-app/BwSpike.zip, then checks the storefront custom endpoint, its writes into the app's custom entity,
concurrent requests, HTTP caching of GET and Admin API read/delete of the rows. Usage (Shopware on 127.0.0.1:8480):
(cd observation-app && zip -qr BwSpike.zip BwSpike) && python3 -I observation_spike.py observation-app/BwSpike.zip"""

import concurrent.futures
import json
import sys
import urllib.parse
import urllib.request
import uuid

import os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import shopware_spike as s  # noqa: E402

out = {}
admin = s.admin_token()
zip_path = sys.argv[1]
boundary = uuid.uuid4().hex
body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="BwSpike.zip"\r\nContent-Type: application/zip\r\n\r\n').encode() + open(zip_path, 'rb').read() + f'\r\n--{boundary}--\r\n'.encode()
req = urllib.request.Request(s.BASE + '/api/_action/extension/upload', data=body, method='POST', headers={'Authorization': 'Bearer ' + admin, 'Content-Type': f'multipart/form-data; boundary={boundary}'})
try:
    out['upload'] = urllib.request.urlopen(req, timeout=120).status
except urllib.error.HTTPError as e:
    out['upload'] = f'{e.code}:{e.read()[:300]!r}'
out['install'] = s.call('POST', '/api/_action/extension/refresh', admin, {})['status'] and None
out['install'] = s.call('POST', '/api/_action/extension/install/app/BwSpike', admin, {})['status']
out['activate'] = s.call('PUT', '/api/_action/extension/activate/app/BwSpike', admin, {})['status']
app = s.call('POST', '/api/search/app', admin, {'filter': [{'type': 'equals', 'field': 'name', 'value': 'BwSpike'}], 'associations': {'aclRole': {}}})['json']['data'][0]
out['privileges'] = sorted(app['aclRole']['privileges'])


def storefront(method, query=None, form=None):
    url = s.BASE + '/storefront/script/bw-observe' + ('?' + urllib.parse.urlencode(query) if query else '')
    data = urllib.parse.urlencode(form).encode() if form is not None else None
    r = urllib.request.Request(url, data=data, method=method, headers={'Host': 'localhost', **({'Content-Type': 'application/x-www-form-urlencoded'} if data else {})})
    try:
        with urllib.request.urlopen(r, timeout=30) as resp:
            return {'status': resp.status, 'body': resp.read()[:300].decode('utf-8', 'replace'), 'cache': resp.headers.get('Cache-Control')}
    except urllib.error.HTTPError as e:
        return {'status': e.code, 'body': e.read()[:600].decode('utf-8', 'replace')}


def rows():
    r = s.call('POST', '/api/search/custom-entity-bw-checkout-report', admin, {'limit': 100, 'total-count-mode': 1})
    return r['json'] if r['status'] == 200 else {'error': r}


for _ in range(5):
    old = [r['id'] for r in (rows().get('data') or [])]
    if old:
        s.call('POST', '/api/_action/sync', admin, [{'action': 'delete', 'entity': 'custom_entity_bw_checkout_report', 'payload': [{'id': i} for i in old]}])
s.call('POST', '/api/_action/sync', admin, [{'action': 'delete', 'entity': 'custom_entity_bw_checkout_setting', 'payload': [{'id': '0' * 31 + '1'}]}])
out['post_before_setting'] = storefront('POST', form={'page': 'confirm', 'stages': 'script_loaded,form_ready', 'errors': '2'})
out['setting_upsert'] = s.call('POST', '/api/_action/sync', admin, [{'action': 'upsert', 'entity': 'custom_entity_bw_checkout_setting', 'payload': [{'id': '0' * 31 + '1', 'observe': True}]}])['status']
out['post'] = storefront('POST', form={'page': 'confirm', 'stages': 'script_loaded,form_ready,bogus,form_ready', 'errors': '99'})
out['get_1'] = storefront('GET', query={'page': 'confirm', 'stages': 'rendered', 't': '1'})
out['get_same_url'] = storefront('GET', query={'page': 'confirm', 'stages': 'rendered', 't': '1'})
out['bad_page'] = storefront('POST', form={'page': 'evil', 'stages': 'script_loaded'})
with concurrent.futures.ThreadPoolExecutor(20) as pool:
    results = list(pool.map(lambda i: storefront('POST', form={'page': 'finish', 'stages': 'finish_reached'})['status'], range(40)))
out['concurrent_statuses'] = sorted(set(results))
data = rows()
out['rows_total'] = data.get('total') if isinstance(data, dict) else data
sample = (data.get('data') or [{}])[0] if isinstance(data, dict) else {}
out['row_fields'] = sorted(k for k in sample.keys()) if sample else None
out['rows'] = __import__('collections').Counter((r.get('page'), r.get('stages'), r.get('errors')) for r in (data.get('data') or [])).most_common() if isinstance(data, dict) else None
ids = [r['id'] for r in (data.get('data') or [])] if isinstance(data, dict) else []
out['delete'] = s.call('POST', '/api/_action/sync', admin, [{'action': 'delete', 'entity': 'custom_entity_bw_checkout_report', 'payload': [{'id': i} for i in ids]}])['status']
out['rows_after_delete'] = rows().get('total')
print('BW_SPIKE=' + json.dumps(out, default=str))
