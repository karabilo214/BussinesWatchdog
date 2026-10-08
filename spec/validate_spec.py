"""Static checks of specification files; no application/provider integration tests.
Run: python3 validate_spec.py. Requires PyYAML only.
"""
import hashlib
import json
import re
import uuid
from datetime import datetime
from pathlib import Path
import yaml

ROOT = Path(__file__).parent
checks = []

def checked(label):
    checks.append(label)

def resolve(doc, ref):
    assert ref.startswith('#/'), ref
    value = doc
    for key in ref[2:].split('/'):
        value = value[key.replace('~1','/').replace('~0','~')]
    return value

def validate(value, schema, root):
    """Validator of the JSON Schema subset used here; not a general schema engine."""
    if '$ref' in schema:
        if schema['$ref'].startswith('#/'):
            return validate(value, resolve(root,schema['$ref']),root)
        assert schema['$ref']=='./event.schema.json'
        event_schema=json.loads((ROOT/'contracts/event.schema.json').read_text())
        return validate(value,event_schema,event_schema)
    if 'const' in schema: assert value==schema['const'], (value,schema['const'])
    if 'enum' in schema: assert value in schema['enum'], (value,schema['enum'])
    if 'type' in schema:
        types=schema['type'] if isinstance(schema['type'],list) else [schema['type']]
        predicates={'object':lambda: isinstance(value,dict),'array':lambda: isinstance(value,list),
                    'string':lambda: isinstance(value,str),'integer':lambda: type(value) is int,
                    'number':lambda: type(value) in (int,float),'boolean':lambda: type(value) is bool,
                    'null':lambda: value is None}
        assert any(predicates[t]() for t in types),(value,types)
    if isinstance(value,dict):
        for field in schema.get('required',[]): assert field in value, field
        if schema.get('additionalProperties') is False:
            assert set(value)<=set(schema.get('properties',{})),set(value)-set(schema.get('properties',{}))
        for field,sub in schema.get('properties',{}).items():
            if field in value: validate(value[field],sub,root)
    if isinstance(value,str):
        if 'pattern' in schema: assert re.search(schema['pattern'],value),(value,schema['pattern'])
        assert len(value)>=schema.get('minLength',0)
        assert len(value)<=schema.get('maxLength',10**9)
        if schema.get('format')=='uuid': uuid.UUID(value)
        if schema.get('format')=='date-time': datetime.fromisoformat(value.replace('Z','+00:00'))
    if type(value) in (int,float):
        assert value>=schema.get('minimum',float('-inf'))
        assert value<=schema.get('maximum',float('inf'))
    if isinstance(value,list):
        assert len(value)>=schema.get('minItems',0)
        assert len(value)<=schema.get('maxItems',10**9)
        for v in value:
            if 'items' in schema: validate(v,schema['items'],root)
    for sub in schema.get('allOf',[]): validate(value,sub,root)
    if 'oneOf' in schema:
        successes=0
        for sub in schema['oneOf']:
            try: validate(value,sub,root); successes+=1
            except (AssertionError,ValueError,KeyError,TypeError): pass
        assert successes==1, ('oneOf',successes)
    if 'if' in schema:
        try: validate(value,schema['if'],root)
        except (AssertionError,ValueError,KeyError,TypeError):
            if 'else' in schema: validate(value,schema['else'],root)
        else:
            if 'then' in schema: validate(value,schema['then'],root)

def refs(value, doc, base):
    if isinstance(value,dict):
        if '$ref' in value:
            ref=value['$ref']
            if ref.startswith('#/'): resolve(doc,ref)
            elif not ref.startswith('http'):
                file=base/ref.split('#')[0]
                assert file.is_file(),ref
        for sub in value.values(): refs(sub,doc,base)
    elif isinstance(value,list):
        for sub in value: refs(sub,doc,base)

def split_fields(s):
    fields=[]; start=0; depth=0; quoted=False; i=0
    while i<len(s):
        ch=s[i]
        if ch=="'":
            if quoted and i+1<len(s) and s[i+1]=="'": i+=2; continue
            quoted=not quoted
        elif not quoted:
            if ch=='(': depth+=1
            elif ch==')': depth-=1; assert depth>=0
            elif ch==',' and depth==0: fields.append(s[start:i].strip());start=i+1
        i+=1
    assert depth==0 and not quoted
    fields.append(s[start:].strip())
    return fields

api=yaml.safe_load((ROOT/'contracts/openapi.yaml').read_text())
assert api['openapi']=='3.1.0'
refs(api,api,ROOT/'contracts')
ops=[]
for path,item in api['paths'].items():
    for method,op in item.items():
        if method not in ('get','post','patch','delete','put'): continue
        assert 'responses' in op and 'operationId' in op
        ops.append(op['operationId'])
        params=[]
        for param in op.get('parameters',[]):
            params.append(resolve(api,param['$ref']) if '$ref' in param else param)
        placeholders=set(re.findall(r'\{(.*?)\}',path))
        assert placeholders=={p['name'] for p in params if p['in']=='path'},path
assert len(ops)==len(set(ops))
checked(f'OpenAPI YAML, internal references, path parameters and unique operation IDs: {len(ops)} operations')

extra_api=yaml.safe_load((ROOT/'contracts/platform-billing-api.yaml').read_text())
refs(extra_api,extra_api,ROOT/'contracts')
extra_ops=[]
for path,item in extra_api['paths'].items():
    for method,op in item.items():
        if method not in ('get','post','patch','delete','put'): continue
        extra_ops.append(op['operationId'])
        parameters=[resolve(extra_api,p['$ref']) if '$ref' in p else p for p in op.get('parameters',[])]
        assert set(re.findall(r'\{(.*?)\}',path))=={p['name'] for p in parameters if p['in']=='path'}
assert len(extra_ops)==20 and len(set(extra_ops+ops))==len(extra_ops+ops)
purchase={'price_id':'44444444-4444-4444-8444-444444444444','policy_version_ids':['55555555-5555-4555-8555-555555555555'],'return_path':'/checkout/return'}
validate(purchase,extra_api['components']['schemas']['CheckoutRequest'],extra_api)
for key,value in [('amount_minor','1'),('return_path','https://evil.example.invalid')]:
    invalid=dict(purchase);invalid[key]=value
    try:validate(invalid,extra_api['components']['schemas']['CheckoutRequest'],extra_api)
    except (AssertionError,ValueError,KeyError,TypeError):pass
    else:raise AssertionError('Forged checkout field accepted')
refund_action={'action_type':'request_refund','target_tenant_id':'66666666-6666-4666-8666-666666666666','reason':'Synthetic owner request','parameters':{'payment_attempt_id':'77777777-7777-4777-8777-777777777777','amount_minor':'1000','currency':'EUR'}}
validate(refund_action,extra_api['components']['schemas']['PlatformActionRequest'],extra_api)
invalid=json.loads(json.dumps(refund_action));invalid['parameters']['execute_sql']='DROP TABLE users'
try:validate(invalid,extra_api['components']['schemas']['PlatformActionRequest'],extra_api)
except (AssertionError,ValueError,KeyError,TypeError):pass
else:raise AssertionError('Arbitrary action parameter accepted')
checked('Extension OpenAPI: 20 operations, references/paths and positive/negative purchase/action DTO fixtures')

event_schema=json.loads((ROOT/'contracts/event.schema.json').read_text())
refs(event_schema,event_schema,ROOT/'contracts')
batch=json.loads((ROOT/'examples/ingest-batch.json').read_text())
request=api['paths']['/api/v1/ingest/events']['post']['requestBody']['content']['application/json']['schema']
validate(batch,request,api)
checked('Positive Woo ingestion fixture conforms to the used JSON Schema subset')
bad=[]
for description,transform in [
    ('float money',lambda e: e['data'].update(total_minor=184.0)),
    ('negative money',lambda e: e['data'].update(total_minor='-1')),
    ('unsupported schema',lambda e:e.update(schema_version='2.0')),
    ('missing revision',lambda e:e.pop('aggregate_revision')),
    ('wrong aggregate',lambda e:e.update(aggregate_type='payment')),
    ('unexpected PII',lambda e:e['data'].update(customer_email='forbidden@example.invalid')),
]:
    example=json.loads(json.dumps(batch['events'][0]));transform(example)
    try: validate(example,event_schema,event_schema)
    except (AssertionError,ValueError,KeyError,TypeError): bad.append(description)
    else: raise AssertionError('Invalid fixture accepted: '+description)
checked(f'Negative event schema fixture checks: {len(bad)}')

cases=json.loads((ROOT/'examples/reconciliation-cases.json').read_text())['cases']
assert len({c['id'] for c in cases})==len(cases)
money_cases=0
for case in cases:
    inp=case['input']; exp=case.get('arithmetic_reference',case['expected'])
    if {'gross_minor','captured_minor','refund_expected_minor','refund_actual_minor'}<=set(inp):
        g,c,rw,rp=(int(inp[x]) for x in ('gross_minor','captured_minor','refund_expected_minor','refund_actual_minor'))
        for n in (g,c,rw,rp): assert 0<=n<=9223372036854775807
        assert int(exp['capture_difference_minor'])==c-g,case['id']
        assert int(exp['refund_difference_minor'])==rp-rw,case['id']
        assert int(exp['net_difference_minor'])==(c-rp)-(g-rw),case['id']
        if case['expected']['result'] in ('pending','unknown','unsupported','unmatched','unknown_refund'):
            assert case['expected']['capture_difference_minor'] is None
            assert case['expected']['publish_monetary_mismatch'] is False
        money_cases+=1
checked(f'Unique financial case IDs: {len(cases)}; exact integer arithmetic checked: {money_cases}')

sql=(ROOT/'database/schema.sql').read_text()+'\n'+(ROOT/'database/platform-billing-extension.sql').read_text()
sql=re.sub(r'--[^\n]*','',sql)
matches=list(re.finditer(r'CREATE TABLE\s+(\w+)\s*\((.*?)\);',sql,re.S))
tables={};uniques={};foreign_keys=[]
for m in matches:
    name=m.group(1);assert name not in tables
    columns=set();keys=[]
    for field in split_fields(m.group(2)):
        if re.match(r'FOREIGN KEY',field):
            fm=re.search(r'FOREIGN KEY\s*\((.*?)\)\s*REFERENCES\s+(\w+)\s*\((.*?)\)',field,re.S)
            assert fm,field
            local=tuple(x.strip() for x in fm[1].split(','));remote=tuple(x.strip() for x in fm[3].split(','))
            foreign_keys.append((name,local,fm[2],remote))
        elif field.startswith(('PRIMARY KEY','UNIQUE')):
            km=re.search(r'\((.*?)\)',field);assert km
            keys.append(tuple(x.strip() for x in km[1].split(',')))
        elif field.startswith('CHECK'): pass
        else:
            col=field.split()[0];assert col not in columns;columns.add(col)
            if 'PRIMARY KEY' in field or re.search(r'\bUNIQUE\b',field):keys.append((col,))
            fm=re.search(r'REFERENCES\s+(\w+)\s*\((.*?)\)',field)
            if fm: foreign_keys.append((name,(col,),fm[1],tuple(x.strip() for x in fm[2].split(','))))
    tables[name]=columns;uniques[name]=keys
assert len(tables)==62,len(tables)
for alter in re.finditer(r'ALTER TABLE\s+(\w+)\s+ADD COLUMN\s+(\w+)',sql):
    assert alter[1] in tables and alter[2] not in tables[alter[1]]
    tables[alter[1]].add(alter[2])
for alter in re.finditer(r'ALTER TABLE\s+(\w+)\s+ADD CONSTRAINT\s+\w+\s+FOREIGN KEY\s*\((.*?)\)\s*REFERENCES\s+(\w+)\s*\((.*?)\)',sql,re.S):
    foreign_keys.append((alter[1],tuple(x.strip() for x in alter[2].split(',')),alter[3],tuple(x.strip() for x in alter[4].split(','))))
for table,keys in uniques.items():
    for key in keys: assert set(key)<=tables[table],(table,key)
for table,cols,remote,rcols in foreign_keys:
    assert set(cols)<=tables[table],(table,cols)
    assert remote in tables and set(rcols)<=tables[remote],(remote,rcols)
    assert len(cols)==len(rcols)
    assert rcols in uniques[remote],(table,remote,rcols)
for im in re.finditer(r'CREATE (?:UNIQUE )?INDEX\s+(\w+)\s+ON\s+(\w+)\s*\((.*?)\)',sql,re.S):
    assert im[2] in tables,im[2]
    # Function expression lower(email) is checked by known column tokens.
    words=set(re.findall(r'\b[a-z_][a-z_0-9]*\b',im[3]))-{'lower','desc','asc'}
    assert words<=tables[im[2]],(im[1],words)
checked(f'SQL static structural checks: {len(tables)} tables, {len(foreign_keys)} FK targets/unique keys, index columns and balanced field lists')

main=(ROOT/'Business-Watchdog-TZ.md').read_text()
sections=re.findall(r'^## (\d+) ',main,re.M)
assert sections==[str(i) for i in range(1,43)]
acc=(ROOT/'ACCEPTANCE.md').read_text()
ids=re.findall(r'\| (ACC-\d+) \|',acc)
assert ids==[f'ACC-{i:02d}' for i in range(1,51)]
for path in ['database/schema.sql','contracts/openapi.yaml','contracts/event.schema.json','contracts/ui-api-catalog.md','examples/reconciliation-cases.json','CODEX-IMPLEMENTATION.md','ACCEPTANCE.md','README.md']:
    assert (ROOT/path).is_file(),path
checked('42 numbered TZ sections, 50 unique acceptance IDs, all listed deliverables present')

extra_files=['panels/OWNER-PANEL.md','panels/ADMIN-PANEL.md','panels/CLIENT-PANELS.md','panels/PUBLIC-SUBSCRIPTION-FRONTEND.md','panels/ADMIN-BACKEND.md','database/platform-billing-extension.sql','contracts/platform-billing-api.yaml','examples/saas-billing-cases.json','PANELS-ACCEPTANCE.md']
for path in extra_files:assert (ROOT/path).is_file(),path
billing_cases=json.loads((ROOT/'examples/saas-billing-cases.json').read_text())['cases']
assert len(billing_cases)==16 and len({c['id'] for c in billing_cases})==16
extra_acc=re.findall(r'\| (EXT-\d+) \|',(ROOT/'PANELS-ACCEPTANCE.md').read_text())
assert extra_acc==[f'EXT-{i:02d}' for i in range(1,29)]
checked('Extension: five panel specifications, 16 unique billing cases, 28 additional acceptance scenarios')

report=['# Проверка пакета спецификации','',
'Проверено 8 октября 2026 года для версии1.1. Ниже перечислены фактически выполненные проверки файлов, а не тесты рабочего SaaS.','']
report += ['- '+x for x in checks]
report += ['', 'Ограничения проверки', '',
'- PostgreSQL server и полноценный PostgreSQL parser в текущем окружении недоступны. SQL проверен статически по структуре, полям, FK и уникальным ключам; выполнение DDL в PostgreSQL18 и Laravel migrations обязательно на D1.',
'- JSON Schema проверен ограниченным валидатором используемых конструкций. В CI реализации требуется стандартный Draft2020-12 validator и полная OpenAPI validation.',
'- Финансовые классификации fixtures являются ожидаемыми результатами; бизнес-движок пока не написан. Здесь проверена арифметика примеров, а не correctness будущего detector.',
'- WooCommerce, Stripe sandbox, browser network policy, load/restore/security tests ещё не выполнялись: это gates соответствующих implementation этапов.',
'- Нет DOCX/PDF: основной формат Markdown сохраняет схемы и code blocks для работы владельца и Codex.', '']
(ROOT/'VALIDATION.md').write_text('\n'.join(report))
print(json.dumps({'status':'passed_static_checks','checks':checks,'limitations':'No live PostgreSQL/provider/application execution'},ensure_ascii=False,indent=2))
