"""Generates api-tests/bruno/WAFFRLI-Admin-API (python3 admin.py). Do not edit the .bru files by hand."""
import os, json, textwrap, shutil

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'WAFFRLI-Admin-API'))
if os.path.isdir(ROOT):
    for name in os.listdir(ROOT):
        if name in ('fixtures', '.env', 'README.md'):
            continue
        p = os.path.join(ROOT, name)
        shutil.rmtree(p) if os.path.isdir(p) else os.remove(p)
os.makedirs(ROOT, exist_ok=True)


def w(path, content):
    full = os.path.join(ROOT, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    open(full, 'w').write(content.rstrip() + "\n")


w('bruno.json', json.dumps({"version": "1", "name": "WAFFRLI-Admin-API", "type": "collection", "ignore": ["node_modules", ".git"]}, indent=2))
w('collection.bru', '''
meta {
  name: WAFFRLI-Admin-API
}

headers {
  Accept: application/json
  Accept-Language: {{locale}}
}

auth {
  mode: bearer
}

auth:bearer {
  token: {{admin_token}}
}

docs {
  WAFFRLI platform administration API (/api/v1/admin, guard `admin`, Sanctum).
  Every protected request inherits the Bearer token captured by
  "Authentication / Login (success)". Each resource also requires the
  matching admin permission (Super Admin has all of them).
  Records created by this collection use random names/codes and are only
  DEACTIVATED at the end: the API has no delete route by design.
  The Filament panel (/admin) uses a separate session guard and is not
  part of this collection. Secrets come from the git-ignored .env file.
}
''')
for name, url in {'Local': 'http://localhost:8080', 'Shared-Dev': 'https://dev-api.example.invalid'}.items():
    w(f'environments/{name}.bru', f'''
vars {{
  base_url: {url}
  locale: en
  admin_email: {{{{process.env.WAFFRLI_ADMIN_EMAIL}}}}
  admin_password: {{{{process.env.WAFFRLI_ADMIN_PASSWORD}}}}
  latitude: 33.5100
  longitude: 36.2900
}}
vars:secret [
  admin_token
]
''')
w('.env.example', '''
# Copy to .env (git-ignored) in this folder. Bruno reads it as process.env.
# Use a LOCAL/DEV admin only (php artisan waffrli:create-super-admin).
# Never commit real passwords or tokens.
WAFFRLI_ADMIN_EMAIL=
WAFFRLI_ADMIN_PASSWORD=
''')
w('.gitignore', '.env\n')

FOLDERS = ['Authentication', 'Settings', 'Currencies', 'Cities', 'Delivery Areas', 'Delivery Fee Rules',
           'Customer Accounts', 'Password Reset', 'Authorization and Negative Tests']
F = {}
for i, name in enumerate(FOLDERS, 1):
    F[name] = f'{i:02d} {name}'
    w(f'{F[name]}/folder.bru', f'meta {{\n  name: {name}\n  seq: {i}\n}}\n')


def req(folder, seq, name, method, url, body=None, auth='inherit', tests='', post='', pre='', docs='', query=None):
    parts = [f'meta {{\n  name: {name}\n  type: http\n  seq: {seq}\n}}\n',
             f'{method} {{\n  url: {{{{base_url}}}}/api/v1/admin/{url}{("?" + "&".join(f"{k}={v}" for k, v in query.items())) if query else ""}\n  body: {"none" if body is None else "json"}\n  auth: {auth}\n}}\n']
    if query:
        parts.append('params:query {\n' + ''.join(f'  {k}: {v}\n' for k, v in query.items()) + '}\n')
    if body is not None:
        parts.append('body:json {\n' + textwrap.indent(textwrap.dedent(body).strip(), '  ') + '\n}\n')
    for block, text in (('script:pre-request', pre), ('script:post-response', post), ('tests', tests)):
        if text:
            parts.append(block + ' {\n' + textwrap.indent(textwrap.dedent(text).strip(), '  ') + '\n}\n')
    if docs:
        parts.append('docs {\n' + textwrap.indent(textwrap.dedent(docs).strip(), '  ') + '\n}\n')
    w(f'{F[folder]}/{name}.bru', '\n'.join(parts))


ENV = '''
test("standard envelope", function () {
  const b = res.getBody();
  expect(b).to.include.all.keys("data", "status", "error", "statusCode");
  expect(b.statusCode).to.equal(res.getStatus());
});
'''


def status(code, extra=''):
    ok = 'true' if code < 300 else 'false'
    return f'''
test("HTTP {code}", function () {{
  expect(res.getStatus()).to.equal({code});
}});
test("status flag is {ok}", function () {{
  expect(res.getBody().status).to.equal({ok});
}});
''' + ENV + extra


def ok(extra=''):
    return status(200, extra)


def refused(*codes):
    return f'''
test("refused ({', '.join(map(str, codes))})", function () {{
  expect(res.getStatus()).to.be.oneOf([{', '.join(map(str, codes))}]);
  expect(res.getBody().status).to.equal(false);
  expect(res.getBody().data).to.equal(null);
}});
''' + ENV


def no_secrets():
    return 'test("never serializes credentials", function () {\n  const raw = JSON.stringify(res.getBody());\n  expect(raw).to.not.contain("password");\n  expect(raw).to.not.match(/\\$2y\\$/);\n});'


def listed(key):
    return ok(f'test("{key} listed", function () {{\n  expect(res.getBody().data.{key}).to.be.an("array");\n}});')


def paginated(key):
    return ok(f'''
test("paginated {key}", function () {{
  const d = res.getBody().data;
  expect(d.{key}).to.be.an("array");
  expect(d.{key}.length).to.be.at.most(5);
  expect(d).to.include.all.keys("current_page", "next_page", "previous_page", "total_pages");
}});
''')


def crud(folder, path, single, plural, var, create_body, update_body, create_pre='', create_tests='', invalid_body='{}', has_toggle=True, page_key=None):
    s = 1
    req(folder, s, 'List all', 'get', f'{path}/all', tests=listed(plural)); s += 1
    req(folder, s, 'List paginated', 'get', f'{path}/all/paginated', query={'per_page': '5', 'page': '1'}, tests=paginated(page_key or plural),
        docs=f'Legacy starter contract: the paginated list key is `{page_key or plural}` (lower-cased model name, plural)' + (f', which differs from `{plural}` returned by /all.' if page_key else '.')); s += 1
    req(folder, s, 'Create', 'post', f'{path}/create', body=create_body, pre=create_pre,
        post=f'if (res.getStatus() === 200) {{ bru.setVar("{var}", String(res.getBody().data.{single}.id)); }}',
        tests=ok(create_tests or f'test("created", function () {{\n  expect(res.getBody().data.{single}.id).to.be.a("number");\n}});'),
        docs='Random unique values: safe to run repeatedly.'); s += 1
    req(folder, s, 'Create - validation error', 'post', f'{path}/create', body=invalid_body, tests=status(400, 'test("first validation message", function () {\n  expect(res.getBody().error).to.be.a("string").and.not.empty;\n});')); s += 1
    req(folder, s, 'Show', 'post', f'{path}/show', body=f'{{\n  "id": {{{{{var}}}}}\n}}', tests=ok(f'test("same record", function () {{\n  expect(String(res.getBody().data.{single}.id)).to.equal(bru.getVar("{var}"));\n}});')); s += 1
    req(folder, s, 'Show - not found', 'post', f'{path}/show', body='{\n  "id": 99999999\n}', tests=refused(400, 404)); s += 1
    req(folder, s, 'Update', 'post', f'{path}/update', body=update_body, tests=ok()); s += 1
    if has_toggle:
        req(folder, s, 'Deactivate', 'post', f'{path}/deactivate', body=f'{{\n  "id": {{{{{var}}}}}\n}}', tests=ok()); s += 1
        req(folder, s, 'Activate', 'post', f'{path}/activate', body=f'{{\n  "id": {{{{{var}}}}}\n}}', tests=ok()); s += 1
        req(folder, s, 'Deactivate (leave test record inactive)', 'post', f'{path}/deactivate', body=f'{{\n  "id": {{{{{var}}}}}\n}}', tests=ok(),
            docs='The API never deletes: the record created by this run is left inactive.'); s += 1
    return s


# ---------------- Authentication ----------------
A = 'Authentication'
req(A, 1, 'Ping (public health check)', 'get', 'ping', auth='none', tests='test("HTTP 200", function () {\n  expect(res.getStatus()).to.equal(200);\n  expect(res.getBody().status).to.equal("ok");\n});')
req(A, 2, 'Login - invalid credentials', 'post', 'login', auth='none', body='{\n  "email": "{{admin_email}}",\n  "password": "definitely-not-the-password"\n}', tests=refused(400, 401, 422),
    docs='Generic message, no account enumeration. The starter auth layer answers 400.')
req(A, 3, 'Login - validation error', 'post', 'login', auth='none', body='{\n  "email": "not-an-email"\n}', tests=status(400))
req(A, 4, 'Login (success)', 'post', 'login', auth='none', body='{\n  "email": "{{admin_email}}",\n  "password": "{{admin_password}}"\n}',
    post='if (res.getStatus() === 200) { bru.setVar("admin_token", res.getBody().data.token); }',
    tests=ok('test("bearer token issued", function () {\n  expect(res.getBody().data.token).to.be.a("string").and.not.empty;\n});\n' + no_secrets()),
    docs='Captures admin_token (runtime only). Throttled (auth-login limiter).')
req(A, 5, 'Login challenge - invalid (challenge not enabled)', 'post', 'login-challenge/verify', auth='none', body='{\n  "challenge_token": "invalid",\n  "otp": "000000"\n}',
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([400, 401, 403, 404, 422]);\n  expect(res.getBody().status).to.equal(false);\n});',
    docs='Login challenges are disabled unless config/otp.php enables the login_challenge action; this request documents that the route never authenticates by itself.')

# ---------------- Settings ----------------
s = crud('Settings', 'settings', 'setting', 'settings', 'setting_id',
         '{\n  "key": "{{setting_key}}",\n  "value": "bruno",\n  "is_monetary": false\n}',
         '{\n  "id": {{setting_id}},\n  "value": "bruno-updated"\n}',
         create_pre='bru.setVar("setting_key", "bruno.test_" + Date.now());',
         invalid_body='{\n  "key": "Invalid Key!",\n  "value": "x"\n}', has_toggle=False)
req('Settings', s, 'Create - duplicate key refused', 'post', 'settings/create', body='{\n  "key": "{{setting_key}}",\n  "value": "again"\n}', tests=status(400))

# ---------------- Currencies ----------------
s = crud('Currencies', 'currencies', 'currency', 'currencies', 'currency_id',
         '{\n  "code": "{{currency_code}}",\n  "name": {\n    "ar": "عملة اختبار",\n    "en": "Bruno test currency"\n  },\n  "symbol": "B",\n  "decimals": 2,\n  "exchange_rate": "2.5"\n}',
         '{\n  "id": {{currency_id}},\n  "symbol": "BT"\n}',
         create_pre='const L = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"; bru.setVar("currency_code", "Z" + L[Math.floor(Math.random()*26)] + L[Math.floor(Math.random()*26)]);',
         invalid_body='{\n  "code": "TOOLONG",\n  "decimals": 9\n}')
req('Currencies', s, 'Create - making it the default is prohibited', 'post', 'currencies/create', body='{\n  "code": "ZZQ",\n  "symbol": "Z",\n  "decimals": 2,\n  "exchange_rate": "1",\n  "is_default": true\n}', tests=status(400),
    docs='There is deliberately no API route that switches the default currency.'); s += 1
req('Currencies', s, 'Initialize default - refused when a default exists', 'post', 'currencies/initialize-default', body='{\n  "id": {{currency_id}}\n}', tests=refused(400, 409, 422),
    docs='Only valid on a fresh installation without a default currency.')

# ---------------- Cities ----------------
crud('Cities', 'cities', 'city', 'cities', 'city_id',
     '{\n  "name": {\n    "ar": "مدينة اختبار",\n    "en": "{{city_name}}"\n  },\n  "center_lat": {{latitude}},\n  "center_lng": {{longitude}},\n  "radius_km": 5,\n  "sort_order": 99\n}',
     '{\n  "id": {{city_id}},\n  "radius_km": 6\n}',
     create_pre='bru.setVar("city_name", "Bruno City " + Date.now());',
     create_tests='test("translations stored", function () {\n  const c = res.getBody().data.city;\n  expect(c.name_translations.en).to.equal(bru.getVar("city_name"));\n  expect(c.name_translations.ar).to.be.a("string");\n});',
     invalid_body='{\n  "name": {\n    "fr": "Ville"\n  },\n  "center_lat": 123\n}')

# ---------------- Delivery Areas ----------------
POLY = '[[[36.30, 33.50], [36.31, 33.50], [36.31, 33.51], [36.30, 33.51], [36.30, 33.50]]]'
crud('Delivery Areas', 'delivery-areas', 'delivery_area', 'delivery_areas', 'delivery_area_id',
     '{\n  "city_id": {{city_id}},\n  "name": {\n    "ar": "منطقة اختبار",\n    "en": "Bruno area"\n  },\n  "boundary": {\n    "type": "Polygon",\n    "coordinates": ' + POLY + '\n  }\n}',
     '{\n  "id": {{delivery_area_id}},\n  "name": {\n    "ar": "منطقة اختبار",\n    "en": "Bruno area (edited)"\n  }\n}',
     invalid_body='{\n  "city_id": {{city_id}},\n  "boundary": {\n    "type": "Polygon",\n    "coordinates": [[[36.3, 33.5], [36.31, 33.5]]]\n  }\n}', page_key='deliveryareas')

# ---------------- Delivery Fee Rules ----------------
s = crud('Delivery Fee Rules', 'delivery-fee-rules', 'delivery_fee_rule', 'delivery_fee_rules', 'fee_rule_id',
         '{\n  "city_id": {{city_id}},\n  "distance_from_km": 0,\n  "distance_to_km": 50,\n  "base_fee": "1.25",\n  "per_km_fee": "0.10"\n}',
         '{\n  "id": {{fee_rule_id}},\n  "base_fee": "1.50"\n}',
         invalid_body='{\n  "distance_from_km": 10,\n  "distance_to_km": 5,\n  "base_fee": "-1"\n}', page_key='deliveryfeerules')
req('Delivery Fee Rules', s, 'Quote a delivery fee', 'post', 'delivery-fee-rules/quote', body='{\n  "city_id": 1,\n  "origin_lat": 33.5100,\n  "origin_lng": 36.2700,\n  "destination_lat": 33.5200,\n  "destination_lng": 36.2900\n}',
    tests=ok('test("quote shape", function () {\n  const q = res.getBody().data.quote;\n  expect(q).to.include.all.keys("covered", "distance_km", "fee");\n});'),
    docs='Server-side pricing preview; the same engine prices checkout.'); s += 1
req('Delivery Fee Rules', s, 'Quote - validation error', 'post', 'delivery-fee-rules/quote', body='{\n  "origin_lat": 999\n}', tests=status(400))

# ---------------- Customer Accounts ----------------
C = 'Customer Accounts'
req(C, 1, 'List customers', 'get', 'users/all', post='const u = (res.getBody().data.users || []).slice(-1)[0]; if (u) { bru.setVar("target_user_id", String(u.id)); }',
    tests=listed('users') + '\n' + no_secrets())
req(C, 2, 'Deactivate a customer', 'post', 'users/deactivate', body='{\n  "id": {{target_user_id}}\n}', tests=ok(), docs='Reversible (is_active); never deletes. The next request re-activates the same customer.')
req(C, 3, 'Activate the customer again', 'post', 'users/activate', body='{\n  "id": {{target_user_id}}\n}', tests=ok())
req(C, 4, 'Activate - unknown customer', 'post', 'users/activate', body='{\n  "id": 99999999\n}', tests=refused(404))

# ---------------- Password Reset ----------------
P = 'Password Reset'
req(P, 1, 'Send reset OTP (generic answer)', 'post', 'password/send-otp', auth='none', body='{\n  "actor": "admin",\n  "email": "nobody@example.test",\n  "channel": "email"\n}',
    tests='test("never reveals whether the account exists", function () {\n  expect(res.getStatus()).to.be.oneOf([200, 400, 422, 429]);\n  expect(JSON.stringify(res.getBody())).to.not.match(/\\b\\d{6}\\b/);\n});',
    docs='The code is e-mailed (Mailpit in Docker) and never returned. Throttled.')
req(P, 2, 'Verify reset OTP - wrong code', 'post', 'password/verify-otp', auth='none', body='{\n  "actor": "admin",\n  "email": "nobody@example.test",\n  "otp": "000000"\n}', tests=refused(400, 401, 422, 429))
req(P, 3, 'Reset - invalid reset token', 'post', 'password/reset', auth='none', body='{\n  "actor": "admin",\n  "email": "nobody@example.test",\n  "reset_token": "invalid",\n  "password": "new-password-123",\n  "password_confirmation": "new-password-123"\n}', tests=refused(400, 401, 422, 429))
req(P, 4, 'Reset - validation error', 'post', 'password/reset', auth='none', body='{\n  "actor": "admin"\n}', tests=status(400))

# ---------------- Authorization and Negative Tests ----------------
Z = 'Authorization and Negative Tests'
req(Z, 1, 'No token - 401', 'get', 'cities/all', auth='none', tests=status(401))
req(Z, 2, 'Invalid token - 401', 'get', 'settings/all', auth='none', pre='req.setHeader("Authorization", "Bearer 1|not-a-real-token");', tests=status(401))
req(Z, 3, 'Logout', 'post', 'logout', tests=ok())
req(Z, 4, 'Token revoked after logout - 401', 'get', 'cities/all', tests=status(401))
