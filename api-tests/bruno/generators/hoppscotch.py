"""Convert the Bruno collections into the shared Hoppscotch workspace format.

    python3 api-tests/bruno/generators/hoppscotch.py

Writes (generated, do not edit):
  api-tests/hoppscotch/waffrli-collections.json   3 collections (Customer, Driver, Admin)
  api-tests/hoppscotch/waffrli-environments.json  Local + Shared-Dev environments

Every Bruno request becomes a native Hoppscotch request (collection v12 /
request v17): URL, headers, auth, body and docs, its scripts AND its
assertions — run through a small compatibility shim (bru.setVar ->
pw.env.set, res.getBody() -> pw.response.body, chai-style expect) — and the
REAL example responses of its route from docs/waffrli/openapi/examples.json.
Secrets are never written: credentials and tokens are secret variables that
each developer fills in locally (Hoppscotch never syncs secret values).
"""
import json, os, re

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, '..', '..', '..'))
BRUNO = os.path.join(ROOT, 'api-tests', 'bruno')
OUT = os.path.join(ROOT, 'api-tests', 'hoppscotch')
INVENTORY = os.path.join(ROOT, 'docs', 'waffrli', 'api-inventory.md')
EXAMPLES = os.path.join(ROOT, 'docs', 'waffrli', 'openapi', 'examples.json')
COLLECTIONS = [('WAFFRLI-Customer', 'WAFFRLI — Customer API'), ('WAFFRLI-Driver', 'WAFFRLI — Driver API'), ('WAFFRLI-Admin-API', 'WAFFRLI — Admin API')]

# Runs before every converted script: maps the Bruno scripting API onto Hoppscotch's pw API.
SHIM = r'''// --- WAFFRLI Bruno compatibility shim (generated) ---
const __vars = (k) => { const v = pw.env.get(k); return v === undefined || v === null ? '' : String(v); };
const bru = {
  setVar: (k, v) => pw.env.set(k, String(v)),
  getVar: (k) => __vars(k),
  interpolate: (s) => String(s).replace(/{{\s*([\w.]+)\s*}}/g, (m, k) => __vars(k)),
};
const __rand = (n) => Array.from({ length: n }, () => Math.floor(Math.random() * 256).toString(16).padStart(2, '0')).join('');
const require = () => ({
  randomUUID: () => { const h = __rand(16); return h.slice(0, 8) + '-' + h.slice(8, 12) + '-4' + h.slice(13, 16) + '-a' + h.slice(17, 20) + '-' + h.slice(20, 32); },
  randomBytes: (n) => ({ toString: () => __rand(n) }),
});
'''
TEST_SHIM = r'''const res = { getStatus: () => pw.response.status, getBody: () => pw.response.body };
const __chain = (actual, negate) => {
  const check = (ok, what) => { pw.expect(negate ? !ok : ok).toBe(true); return api; };
  const keysOf = (o) => (o && typeof o === 'object' ? Object.keys(o) : []);
  const api = {
    equal: (e) => check(actual === e, 'equal'), eql: (e) => check(JSON.stringify(actual) === JSON.stringify(e), 'eql'),
    oneOf: (list) => check(list.includes(actual), 'oneOf'), match: (re) => check(re.test(String(actual)), 'match'),
    contain: (s) => check(String(actual).includes(s), 'contain'), include: (s) => check(Array.isArray(actual) ? actual.includes(s) : String(actual).includes(s), 'include'),
    greaterThan: (n) => check(actual > n, 'gt'), lessThan: (n) => check(actual < n, 'lt'), most: (n) => check(actual <= n, 'most'), least: (n) => check(actual >= n, 'least'),
    a: (t) => check(t === 'array' ? Array.isArray(actual) : typeof actual === t, 'a'), an: (t) => api.a(t),
    property: (k) => check(actual != null && k in actual, 'property'),
    keys: (...ks) => check(ks.flat().every((k) => keysOf(actual).includes(k)), 'keys'),
    get empty() { return check(actual == null || (typeof actual === 'string' || Array.isArray(actual) ? actual.length === 0 : keysOf(actual).length === 0), 'empty'); },
    get true() { return check(actual === true, 'true'); }, get false() { return check(actual === false, 'false'); },
    get null() { return check(actual === null, 'null'); }, get undefined() { return check(actual === undefined, 'undefined'); },
  };
  for (const w of ['to', 'be', 'been', 'is', 'that', 'which', 'and', 'has', 'have', 'with', 'at', 'of', 'same', 'all', 'deep']) Object.defineProperty(api, w, { get: () => api });
  Object.defineProperty(api, 'not', { get: () => __chain(actual, !negate) });
  api.include.all = api;
  return api;
};
const expect = (actual) => __chain(actual, false);
const test = (name, fn) => pw.test(name, fn);
'''


def var(s):
    """Bruno {{var}} -> Hoppscotch <<var>>."""
    return re.sub(r'{{\s*([\w.]+)\s*}}', r'<<\1>>', s)


def parse_bru(text):
    blocks, i, lines = {}, 0, text.splitlines()
    while i < len(lines):
        m = re.match(r'^([\w:-]+) (\{|\[)\s*$', lines[i])
        if not m:
            i += 1
            continue
        name, close = m.group(1), '}' if m.group(2) == '{' else ']'
        body = []
        i += 1
        while i < len(lines) and lines[i] != close:
            body.append(lines[i][2:] if lines[i].startswith('  ') else lines[i])
            i += 1
        blocks[name] = '\n'.join(body)
        i += 1
    return blocks


def kv(block):
    out = []
    for line in (block or '').splitlines():
        if ':' in line:
            k, v = line.split(':', 1)
            out.append((k.strip(), v.strip()))
    return out


def route_patterns():
    routes = []
    for line in open(INVENTORY, encoding='utf-8'):
        m = re.match(r'^\| (GET|POST|PUT|PATCH|DELETE) \| `([^`]+)` \|', line.strip())
        if m:
            routes.append((m.group(1), m.group(2), re.compile('^' + re.sub(r'\\\{[^/]+?\\\}', '[^/]+', re.escape(m.group(2))) + '$')))
    return routes


ROUTES = route_patterns()
EX = json.load(open(EXAMPLES, encoding='utf-8')) if os.path.exists(EXAMPLES) else {}
AUTH_INHERIT = {'authType': 'inherit', 'authActive': True}


def examples_for(method, path, endpoint):
    path = re.sub(r'<<[^>]+>>', 'x', path.split('?')[0])
    route = next((r for m, r, rx in ROUTES if m == method and rx.match(path)), None)
    out = {}
    for status, ex in sorted(((EX.get(f'{method} {route}') or {}).get('responses') or {}).items()) if route else []:
        name = f"{status} — {ex.get('summary') or 'example'}"
        out[name] = {
            'name': name,
            'originalRequest': {'v': '6', 'name': name, 'method': method, 'endpoint': endpoint, 'headers': [], 'params': [],
                                'body': {'contentType': None, 'body': None}, 'auth': AUTH_INHERIT, 'requestVariables': []},
            'status': {'200': 'OK', '400': 'Bad Request', '401': 'Unauthorized', '403': 'Forbidden', '404': 'Not Found', '409': 'Conflict', '422': 'Unprocessable Entity', '429': 'Too Many Requests', '503': 'Service Unavailable'}.get(status, ''),
            'code': int(status) if status.isdigit() else None,
            'headers': [{'key': 'Content-Type', 'value': 'application/json'}],
            'body': json.dumps(ex.get('value'), ensure_ascii=False, indent=2),
        }
    return out, route


def convert_request(path, base_prefix):
    b = parse_bru(open(path, encoding='utf-8').read())
    meta = dict(kv(b.get('meta')))
    method = next(k for k in b if k in ('get', 'post', 'put', 'patch', 'delete'))
    m = dict(kv(b[method]))
    url = m['url']
    endpoint = var(url)
    headers = [{'key': k, 'value': var(v), 'active': True, 'description': ''} for k, v in kv(b.get('headers'))]
    pre = b.get('script:pre-request', '')
    # Bruno can set request headers from a script; Hoppscotch cannot -> a plain header.
    for hk, hv in re.findall(r'req\.setHeader\("([^"]+)",\s*"([^"]+)"\);?', pre):
        headers.append({'key': hk, 'value': hv, 'active': True, 'description': ''})
    pre = re.sub(r'req\.setHeader\("[^"]+",\s*"[^"]+"\);?', '', pre).strip()
    auth = {'none': {'authType': 'none', 'authActive': True}}.get(m.get('auth'), AUTH_INHERIT)
    btype = m.get('body', 'none')
    if btype == 'json':
        body = {'contentType': 'application/json', 'body': var(b.get('body:json', ''))}
    elif btype == 'multipartForm':
        fields = []
        for k, v in kv(b.get('body:multipart-form')):
            is_file = v.startswith('@file(')
            fields.append({'key': k, 'value': [] if is_file else var(v), 'active': True, 'isFile': is_file})
        body = {'contentType': 'multipart/form-data', 'body': fields}
    else:
        body = {'contentType': None, 'body': None}
    post, tests = b.get('script:post-response', '').strip(), b.get('tests', '').strip()
    test_script = (SHIM + TEST_SHIM + '\n' + post + '\n' + tests).strip() if (post or tests) else ''
    docs = b.get('docs', '').strip()
    full_path = re.sub(r'^<<base_url>>', '', endpoint)
    responses, route = examples_for(method.upper(), full_path, endpoint)
    if route:
        docs = (docs + '\n\n' if docs else '') + f'Route: `{method.upper()} {route}`. Same request in Bruno: `{os.path.relpath(path, BRUNO)}`.'
    return int(meta.get('seq', 0)), {
        'v': '17', 'name': meta['name'], 'method': method.upper(), 'endpoint': endpoint, 'params': [],
        'headers': headers, 'preRequestScript': (SHIM + '\n' + pre).strip() if pre else '', 'testScript': test_script,
        'auth': auth, 'body': body, 'requestVariables': [], 'responses': responses, 'description': docs or None,
    }


def collection(name, folders=None, requests=None, auth=None, headers=None, description=None):
    return {'v': 12, 'name': name, 'folders': folders or [], 'requests': requests or [], 'auth': auth or AUTH_INHERIT,
            'headers': headers or [], 'variables': [], 'description': description, 'preRequestScript': '', 'testScript': ''}


def convert_collection(dirname, title):
    root = os.path.join(BRUNO, dirname)
    col = parse_bru(open(os.path.join(root, 'collection.bru'), encoding='utf-8').read())
    token = dict(kv(col.get('auth:bearer'))).get('token', '')
    folders, count = [], 0
    for entry in sorted(os.listdir(root)):
        fdir = os.path.join(root, entry)
        if not os.path.isdir(fdir) or entry in ('environments', 'fixtures'):
            continue
        fmeta = dict(kv(parse_bru(open(os.path.join(fdir, 'folder.bru'), encoding='utf-8').read()).get('meta')))
        reqs = sorted((convert_request(os.path.join(fdir, f), dirname) for f in os.listdir(fdir) if f.endswith('.bru') and f != 'folder.bru'), key=lambda r: r[0])
        count += len(reqs)
        folders.append((int(fmeta.get('seq', 0)), collection(fmeta.get('name', entry), requests=[r for _, r in reqs])))
    headers = [{'key': k, 'value': var(v), 'active': True, 'description': ''} for k, v in kv(col.get('headers'))]
    return collection(title, folders=[f for _, f in sorted(folders, key=lambda x: x[0])], auth={'authType': 'bearer', 'token': var(token), 'authActive': True},
                      headers=headers, description=col.get('docs', '').strip() or None), count


def environments():
    names = {}
    for dirname, _ in COLLECTIONS:
        for env in ('Local', 'Shared-Dev'):
            p = os.path.join(BRUNO, dirname, 'environments', env + '.bru')
            b = parse_bru(open(p, encoding='utf-8').read())
            target = names.setdefault(env, {})
            example = dict(kv('\n'.join(l.replace('=', ':', 1) for l in open(os.path.join(BRUNO, dirname, '.env.example'), encoding='utf-8').read().splitlines() if '=' in l and not l.startswith('#'))))
            for k, v in kv(b.get('vars')):
                from_env = re.match(r'{{process\.env\.(\w+)}}', v)
                if from_env:
                    value = example.get(from_env.group(1), '')
                    secret = bool(re.search(r'password|token|secret', k)) or value == ''
                    v = '' if secret else value
                else:
                    secret = False
                target.setdefault(k, {'key': k, 'initialValue': v, 'currentValue': v, 'secret': secret})
            for k in [x.strip().rstrip(',') for x in (b.get('vars:secret') or '').splitlines() if x.strip()]:
                target.setdefault(k, {'key': k, 'initialValue': '', 'currentValue': '', 'secret': True})
    return [{'v': 2, 'id': f'waffrli-{env.lower()}', 'name': f'WAFFRLI {env}', 'variables': sorted(vs.values(), key=lambda x: (x['secret'], x['key']))} for env, vs in names.items()]


def main():
    os.makedirs(OUT, exist_ok=True)
    cols, total = [], 0
    for dirname, title in COLLECTIONS:
        c, n = convert_collection(dirname, title)
        cols.append(c)
        total += n
        print(f'{title}: {n} requests')
    json.dump(cols, open(os.path.join(OUT, 'waffrli-collections.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump(environments(), open(os.path.join(OUT, 'waffrli-environments.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f'Wrote api-tests/hoppscotch/waffrli-collections.json ({total} requests) and waffrli-environments.json')


if __name__ == '__main__':
    main()
