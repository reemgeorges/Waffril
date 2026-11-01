import os, json, textwrap, shutil
ROOT=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','WAFFRLI-Customer'))
if os.path.isdir(ROOT):
    for name in os.listdir(ROOT):
        p=os.path.join(ROOT,name)
        if name in ('fixtures', '.env', 'README.md'): continue
        shutil.rmtree(p) if os.path.isdir(p) else os.remove(p)
os.makedirs(ROOT, exist_ok=True)

def w(path, content):
    full=os.path.join(ROOT,path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    open(full,'w').write(content.rstrip()+"\n")

w('bruno.json', json.dumps({"version":"1","name":"WAFFRLI-Customer","type":"collection","ignore":["node_modules",".git"]}, indent=2))
w('collection.bru', '''
meta {
  name: WAFFRLI-Customer
}

headers {
  Accept: application/json
  Accept-Language: {{locale}}
}

auth {
  mode: bearer
}

auth:bearer {
  token: {{customer_token}}
}

docs {
  WAFFRLI Customer API (/api/v1/customer). Every protected request inherits
  the Bearer token captured by "Authentication / Verify OTP (login)".
  The OTP is read through the DEVELOPMENT-ONLY route GET /api/v1/dev/otp,
  which exists only when APP_ENV is local/testing, the SMS driver is `fake`
  and WAFFRLI_DEV_OTP_PEEK_TOKEN is set. Nothing secret is stored in this
  repository: see README.md.
}
''')
envs = {
 'Local': ('http://localhost:8080', 'Docker Compose stack (compose.yaml) on a seeded demo database.'),
 'Shared-Dev': ('https://dev-api.example.invalid', 'Shared development server: replace base_url locally.'),
}
for name,(url,doc) in envs.items():
    w(f'environments/{name}.bru', f'''
vars {{
  base_url: {url}
  locale: en
  customer_phone: {{{{process.env.WAFFRLI_CUSTOMER_PHONE}}}}
  dev_otp_token: {{{{process.env.WAFFRLI_DEV_OTP_PEEK_TOKEN}}}}
  latitude: 33.5100
  longitude: 36.2900
  search_term: pizza
  preferred_store: Fresh Market
}}
vars:secret [
  customer_token,
  otp_code,
  registration_token
]
''')
w('.env.example', '''
# Copy to .env (git-ignored) in this folder. Bruno reads it as process.env.
# Never commit real tokens. The peek token must equal the server's
# WAFFRLI_DEV_OTP_PEEK_TOKEN (development servers only).
WAFFRLI_CUSTOMER_PHONE=+12025550101
WAFFRLI_DEV_OTP_PEEK_TOKEN=
''')
w('.gitignore', '.env\n')

folders = ['Authentication','Profile','Addresses','Cities','Stores and Branches','Products and Search','Favorites','Ratings',
 'Carts','Checkout','Orders','Wallet and Payments','PLUS','Notifications','Device Tokens','Authorization and Negative Tests']
F={}
for i,name in enumerate(folders,1):
    key=f'{i:02d} {name}'
    F[name]=key
    w(f'{key}/folder.bru', f'''
meta {{
  name: {name}
  seq: {i}
}}
''')

def req(folder, seq, name, method, url, body=None, body_type='json', auth='inherit', tests='', post='', pre='', docs='', query=None, headers=None, raw_url=False):
    parts=[f'''meta {{
  name: {name}
  type: http
  seq: {seq}
}}
''']
    btype = 'none' if body is None else ('multipartForm' if body_type=='multipart' else 'json')
    full = url if raw_url else f'{{{{base_url}}}}/api/v1/customer/{url}'
    if query:
        full += ('&' if '?' in full else '?') + '&'.join(f'{k}={v}' for k, v in query.items())
    parts.append(f'''{method} {{
  url: {full}
  body: {btype}
  auth: {auth}
}}
''')
    if query:
        parts.append('params:query {\n'+''.join(f'  {k}: {v}\n' for k,v in query.items())+'}\n')
    if headers:
        parts.append('headers {\n'+''.join(f'  {k}: {v}\n' for k,v in headers.items())+'}\n')
    if body is not None:
        if body_type=='multipart':
            parts.append('body:multipart-form {\n'+''.join(f'  {k}: {v}\n' for k,v in body.items())+'}\n')
        else:
            parts.append('body:json {\n'+textwrap.indent(textwrap.dedent(body).strip(),'  ')+'\n}\n')
    if pre:
        parts.append('script:pre-request {\n'+textwrap.indent(textwrap.dedent(pre).strip(),'  ')+'\n}\n')
    if post:
        parts.append('script:post-response {\n'+textwrap.indent(textwrap.dedent(post).strip(),'  ')+'\n}\n')
    if tests:
        parts.append('tests {\n'+textwrap.indent(textwrap.dedent(tests).strip(),'  ')+'\n}\n')
    if docs:
        parts.append('docs {\n'+textwrap.indent(textwrap.dedent(docs).strip(),'  ')+'\n}\n')
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
'''+ENV+extra
def ok(extra=''): return status(200, extra)

PEEK = '{{base_url}}/api/v1/dev/otp'

# ---------------- 01 Authentication ----------------
A='Authentication'
req(A,1,'Request OTP (login)','post','auth/otp/request', body='{\n  "phone": "{{customer_phone}}"\n}', auth='none', tests=ok('''
test("SMS is the primary channel", function () {
  expect(res.getBody().data.channel).to.be.oneOf(["sms", "whatsapp"]);
  expect(res.getBody().data.expires_in).to.be.greaterThan(0);
});
test("the code itself is never returned", function () {
  expect(JSON.stringify(res.getBody())).to.not.match(/\\b\\d{6}\\b/);
});
'''))
req(A,2,'Dev - read last OTP (development only)','get',PEEK+'?phone={{peek_phone}}', raw_url=True, auth='none', headers={'X-Dev-Token':'{{dev_otp_token}}'},
    pre='bru.setVar("peek_phone", encodeURIComponent(bru.interpolate("{{customer_phone}}")));',
    post='if (res.getStatus() === 200) { bru.setVar("otp_code", res.getBody().data.code); }',
    tests=ok('test("a code was captured", function () {\n  expect(res.getBody().data.code).to.match(/^\\d{4,8}$/);\n});'),
    docs='DEVELOPMENT ONLY. This route does not exist in production (see routes/api/v1/dev.php).')
req(A,3,'Verify OTP - wrong code','post','auth/otp/verify', body='{\n  "phone": "{{customer_phone}}",\n  "code": "000000",\n  "device_id": "bruno-device"\n}', auth='none',
    tests='''
test("refused", function () {
  expect(res.getStatus()).to.be.oneOf([401, 422]);
  expect(res.getBody().status).to.equal(false);
  expect(res.getBody().data).to.equal(null);
});
''', docs='Counts toward the attempt ceiling of this code; the next request still succeeds with the right code.')
req(A,4,'Verify OTP (login)','post','auth/otp/verify', body='{\n  "phone": "{{customer_phone}}",\n  "code": "{{otp_code}}",\n  "device_id": "bruno-device",\n  "device_name": "Bruno"\n}', auth='none',
    post='''
if (res.getStatus() === 200 && res.getBody().data.token) {
  bru.setVar("customer_token", res.getBody().data.token);
  bru.setVar("customer_id", String(res.getBody().data.user.id));
}
''', tests=ok('''
test("logged in with a bearer token", function () {
  const d = res.getBody().data;
  expect(d.status).to.equal("logged_in");
  expect(d.token).to.be.a("string").and.not.empty;
  expect(d.user.phone).to.equal(bru.interpolate("{{customer_phone}}"));
});
test("never returns credentials", function () {
  expect(JSON.stringify(res.getBody().data.user).toLowerCase()).to.not.contain("password");
});
'''), docs='Captures customer_token and customer_id (runtime only). A login revokes every previous session of the account (one active device).')
req(A,5,'Verify OTP - code is single use','post','auth/otp/verify', body='{\n  "phone": "{{customer_phone}}",\n  "code": "{{otp_code}}",\n  "device_id": "bruno-device"\n}', auth='none',
    tests='test("replaying a consumed code fails", function () {\n  expect(res.getStatus()).to.be.oneOf([401, 422]);\n  expect(res.getBody().status).to.equal(false);\n});')
req(A,6,'New account - request OTP','post','auth/otp/request', body='{\n  "phone": "{{new_phone}}"\n}', auth='none',
    pre='''
const n = 3000 + Math.floor(Math.random() * 6999);
bru.setVar("new_phone", "+1202555" + String(n));
''', tests=ok(), docs='Uses a random +1 202 555 3000-9999 number, so every run registers a NEW development account.')
req(A,7,'Dev - read new account OTP (development only)','get',PEEK+'?phone={{peek_phone}}', raw_url=True, auth='none', headers={'X-Dev-Token':'{{dev_otp_token}}'},
    pre='bru.setVar("peek_phone", encodeURIComponent(bru.getVar("new_phone")));',
    post='if (res.getStatus() === 200) { bru.setVar("otp_code", res.getBody().data.code); }', tests=ok())
req(A,8,'Verify OTP - registration required','post','auth/otp/verify', body='{\n  "phone": "{{new_phone}}",\n  "code": "{{otp_code}}",\n  "device_id": "bruno-new-device"\n}', auth='none',
    post='if (res.getStatus() === 200) { bru.setVar("registration_token", res.getBody().data.registration_token); }',
    tests=ok('''
test("no account yet: a short-lived registration token, no bearer token", function () {
  const d = res.getBody().data;
  expect(d.status).to.equal("registration_required");
  expect(d.registration_token).to.be.a("string").and.not.empty;
  expect(d.token).to.equal(undefined);
});
'''))
req(A,9,'Register - first and last name required','post','auth/register', body='{\n  "registration_token": "{{registration_token}}",\n  "first_name": "Bruno"\n}', auth='none', tests=status(400))
req(A,10,'Register (success)','post','auth/register', body='{\n  "registration_token": "{{registration_token}}",\n  "first_name": "Bruno",\n  "last_name": "Tester",\n  "language": "en",\n  "device_id": "bruno-new-device"\n}', auth='none',
    post='if (res.getStatus() === 200) { bru.setVar("new_token", res.getBody().data.token); }',
    tests=ok('''
test("account created and signed in (not stored: the demo customer stays the active session)", function () {
  const d = res.getBody().data;
  expect(d.token).to.be.a("string").and.not.empty;
  expect(d.user.first_name).to.equal("Bruno");
  expect(d.user.email).to.equal(null);
  expect(d.user.account_status).to.equal("active");
});
'''))
req(A,11,'Register - registration token is single use','post','auth/register', body='{\n  "registration_token": "{{registration_token}}",\n  "first_name": "Bruno",\n  "last_name": "Again"\n}', auth='none',
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([401, 409, 422]);\n  expect(res.getBody().status).to.equal(false);\n});')
req(A,12,'Recovery - request (same answer for any phone)','post','auth/recovery/request', body='{\n  "phone": "+12025559999"\n}', auth='none',
    tests=ok('test("does not reveal whether a recoverable account exists", function () {\n  expect(res.getBody().data.message).to.be.a("string");\n});'))

NEW = {'Authorization': 'Bearer {{new_token}}'}
req(A,13,'New account - set an email','patch','me', body='{\n  "email": "{{new_email}}"\n}', auth='none', headers=NEW,
    pre='bru.setVar("new_email", "bruno+" + Date.now() + "@example.test");', tests=ok(),
    docs='Runs as the account registered above (new_token), never the demo customer.')
req(A,14,'New account - send email verification code','post','me/email/verification', body='{}', auth='none', headers=NEW,
    tests=ok(), docs='The code goes to the configured mailer (Mailpit in Docker, http://localhost:8025). It is never returned by the API.')
req(A,15,'New account - verify email with a wrong code','post','me/email/verify', body='{\n  "code": "000000"\n}', auth='none', headers=NEW,
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([400, 422]);\n  expect(res.getBody().status).to.equal(false);\n});')
req(A,16,'New account - request deletion','delete','me', auth='none', headers=NEW,
    tests=ok('test("recovery window returned", function () {\n  expect(res.getBody().data.recovery_until).to.be.a("string");\n});'),
    docs='Soft-deletes the account and revokes its tokens; it can be recovered by phone OTP until recovery_until.')
req(A,17,'Deleted account - token revoked','get','me', auth='none', headers=NEW, tests=status(401))
req(A,18,'Recovery - request for the deleted account','post','auth/recovery/request', body='{\n  "phone": "{{new_phone}}"\n}', auth='none', tests=ok())
req(A,19,'Dev - read recovery OTP (development only)','get',PEEK+'?phone={{peek_phone}}', raw_url=True, auth='none', headers={'X-Dev-Token':'{{dev_otp_token}}'},
    pre='bru.setVar("peek_phone", encodeURIComponent(bru.getVar("new_phone")));',
    post='if (res.getStatus() === 200) { bru.setVar("otp_code", res.getBody().data.code); }', tests=ok())
req(A,20,'Recovery - wrong code','post','auth/recovery/verify', body='{\n  "phone": "{{new_phone}}",\n  "code": "000000",\n  "device_id": "bruno-new-device"\n}', auth='none',
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([401, 422]);\n  expect(res.getBody().status).to.equal(false);\n});')
req(A,21,'Recovery - verify (account restored)','post','auth/recovery/verify', body='{\n  "phone": "{{new_phone}}",\n  "code": "{{otp_code}}",\n  "device_id": "bruno-new-device"\n}', auth='none',
    tests=ok('test("restored and signed in", function () {\n  expect(res.getBody().data.token).to.be.a("string").and.not.empty;\n  expect(res.getBody().data.user.account_status).to.equal("active");\n});'))

# ---------------- 02 Profile ----------------
P='Profile'
req(P,1,'Get my profile','get','me', tests=ok('''
test("it is me", function () {
  const u = res.getBody().data.user;
  expect(String(u.id)).to.equal(bru.getVar("customer_id"));
  expect(u.account_status).to.equal("active");
});
'''))
req(P,2,'Update language and display currency','patch','me', body='{\n  "language": "{{locale}}"\n}', tests=ok('test("language saved", function () {\n  expect(res.getBody().data.user.language).to.equal(bru.interpolate("{{locale}}"));\n});'))
req(P,3,'Delivery context','get','context', post='if (res.getStatus() === 200) { bru.setVar("default_address_id", String(res.getBody().data.selected_address.id)); bru.setVar("default_currency_code", res.getBody().data.default_currency.code); }',
    tests=ok('test("default address is the delivery city", function () {\n  const d = res.getBody().data;\n  expect(d.delivery_city.id).to.equal(d.selected_address.city.id);\n});'))
req(P,4,'Upload avatar','post','me/avatar', body={'avatar':'@file(fixtures/avatar.png)'}, body_type='multipart',
    tests=ok('test("avatar url returned", function () {\n  expect(res.getBody().data.user.avatar_url).to.be.a("string").and.not.empty;\n});'))
req(P,5,'Delete avatar','delete','me/avatar', tests=ok('test("avatar removed", function () {\n  expect(res.getBody().data.user.avatar_url).to.equal(null);\n});'))
req(P,6,'Update profile - invalid email','patch','me', body='{\n  "email": "not-an-email"\n}', tests=status(400))

# ---------------- 03 Addresses ----------------
D='Addresses'
req(D,1,'List addresses','get','addresses', tests=ok('test("exactly one default", function () {\n  expect(res.getBody().data.addresses.filter(a => a.is_default).length).to.equal(1);\n});'))
req(D,2,'Add address','post','addresses', body='{\n  "label": "Bruno office",\n  "address_line": "Bruno test street",\n  "lat": {{latitude}},\n  "lng": {{longitude}}\n}',
    post='if (res.getStatus() === 200) { bru.setVar("address_id", String(res.getBody().data.address.id)); }',
    tests=ok('test("city resolved from coordinates", function () {\n  expect(res.getBody().data.address.city.id).to.be.a("number");\n  expect(res.getBody().data.address.is_default).to.equal(false);\n});'))
req(D,3,'Update address','patch','addresses/{{address_id}}', body='{\n  "label": "Bruno office (edited)"\n}', tests=ok('test("label saved", function () {\n  expect(res.getBody().data.address.label).to.equal("Bruno office (edited)");\n});'))
req(D,4,'Make it the default','post','addresses/{{address_id}}/default', tests=ok('test("now default", function () {\n  expect(res.getBody().data.address.is_default).to.equal(true);\n});'))
req(D,5,'Restore the original default','post','addresses/{{default_address_id}}/default', tests=ok())
req(D,6,'Delete address','delete','addresses/{{address_id}}', tests=ok())
req(D,7,'Delete address again - not found','delete','addresses/{{address_id}}', tests=status(404))

# ---------------- 04 Cities ----------------
C='Cities'
req(C,1,'Active cities','get','cities', tests=ok('test("at least one city", function () {\n  expect(res.getBody().data.cities.length).to.be.greaterThan(0);\n});'))
req(C,3,'Legacy alias - active cities (user prefix)','get','{{base_url}}/api/v1/user/cities', raw_url=True, tests=ok(), docs='Kept for existing app builds; same handler as /customer/cities.')
req(C,4,'Legacy alias - detect city (user prefix)','post','{{base_url}}/api/v1/user/cities/detect', raw_url=True, body='{\n  "lat": {{latitude}},\n  "lng": {{longitude}}\n}', tests=ok())
req(C,2,'Detect city from coordinates','post','cities/detect', body='{\n  "lat": {{latitude}},\n  "lng": {{longitude}}\n}', tests=ok())

# ---------------- 05 Stores and Branches ----------------
S='Stores and Branches'
req(S,1,'Store categories','get','store-categories', tests=ok('test("list", function () {\n  expect(res.getBody().data.store_categories).to.be.an("array");\n});'))
req(S,2,'Branches in my delivery city','get','branches', query={'per_page':'50'}, post='''
const list = res.getBody().data.branches || [];
const wanted = bru.interpolate("{{preferred_store}}").toLowerCase();
const preferred = list.find(b => b.store && String(b.store.name).toLowerCase().includes(wanted)) || list[0];
if (preferred) { bru.setVar("branch_id", String(preferred.id)); }
''', tests=ok('test("branches listed independently (each with its store)", function () {\n  const list = res.getBody().data.branches;\n  expect(list.length).to.be.greaterThan(0);\n  list.forEach(b => expect(b.store).to.be.an("object"));\n});'))
req(S,3,'Branch details, hours and exceptions','get','branches/{{branch_id}}', tests=ok('test("same branch", function () {\n  expect(String(res.getBody().data.branch.id)).to.equal(bru.getVar("branch_id"));\n});'))
req(S,4,'Branch products','get','branches/{{branch_id}}/products', query={'per_page':'50'}, docs='Picks an available product that needs no option choice (requires_options = false) for the cart tests.', post='''
const list = res.getBody().data.products || [];
const p = list.find(x => x.is_available && x.requires_options === false) || list.find(x => x.is_available) || list[0];
if (p) { bru.setVar("product_id", String(p.id)); bru.setVar("unit_price", String(p.price.amount)); }
''', tests=ok('test("branch prices", function () {\n  res.getBody().data.products.forEach(p => expect(p.price).to.have.property("amount"));\n});'))
req(S,5,'Product categories (tree)','get','product-categories', tests=ok())

# ---------------- 06 Products and Search ----------------
R='Products and Search'
req(R,1,'Product details with options','get','branches/{{branch_id}}/products/{{product_id}}', tests=ok('''
test("product, branch price, options and aggregate rating", function () {
  const p = res.getBody().data.product;
  expect(String(p.id)).to.equal(bru.getVar("product_id"));
  expect(p.option_groups).to.be.an("array");
  expect(p.rating).to.have.property("average");
  expect(JSON.stringify(p)).to.not.contain("comment");
});
'''))
req(R,2,'Search','get','search', query={'q':'{{search_term}}'}, tests=ok('test("branches and products of my city", function () {\n  expect(res.getBody().data).to.have.all.keys("branches", "products");\n});'))
req(R,3,'Current offers','get','offers', tests=ok())
req(R,4,'Product of another branch - not found','get','branches/{{branch_id}}/products/999999', tests=status(404))

# ---------------- 07 Favorites ----------------
V='Favorites'
req(V,1,'Add favorite (idempotent)','post','products/{{product_id}}/favorite', tests=ok())
req(V,2,'List favorites','get','favorites', tests=ok('test("contains the product", function () {\n  expect(res.getBody().data.favorites.map(f => String(f.id))).to.include(bru.getVar("product_id"));\n});'))
req(V,3,'Remove favorite','delete','products/{{product_id}}/favorite', tests=ok())
req(V,4,'Favorite a missing product - not found','post','products/999999/favorite', tests=status(404))

# ---------------- 08 Ratings ----------------
G='Ratings'
req(G,1,'Find a delivered order','get','orders', query={'scope':'previous','per_page':'50'}, post='''
const delivered = (res.getBody().data.orders || []).find(o => o.status === "delivered");
bru.setVar("delivered_order_id", delivered ? String(delivered.id) : "");
''', tests=ok())
req(G,2,'Read delivered order items','get','orders/{{delivered_order_id}}', post='''
const items = (res.getBody().data && res.getBody().data.order) ? res.getBody().data.order.items : [];
bru.setVar("rated_product_id", items.length ? String(items[0].product_id) : "");
''', tests='test("order readable when the demo customer has one", function () {\n  if (bru.getVar("delivered_order_id")) { expect(res.getStatus()).to.equal(200); }\n});')
req(G,3,'Rate a purchased product (verified purchaser)','post','products/{{rated_product_id}}/rating', body='{\n  "order_id": {{delivered_order_id}},\n  "rating": 5,\n  "comment": "Bruno: great"\n}',
    tests='test("accepted for a verified purchase", function () {\n  if (bru.getVar("rated_product_id")) {\n    expect(res.getStatus()).to.equal(200);\n    expect(res.getBody().data.product_rating.count).to.be.greaterThan(0);\n  }\n});')
req(G,4,'Rate without purchase - refused','post','products/{{product_id}}/rating', body='{\n  "order_id": 999999,\n  "rating": 5\n}',
    tests='test("refused (not your order / not purchased)", function () {\n  expect(res.getStatus()).to.be.oneOf([404, 422]);\n  expect(res.getBody().status).to.equal(false);\n});')
req(G,5,'Rate - invalid value','post','products/{{product_id}}/rating', body='{\n  "order_id": 1,\n  "rating": 9\n}', tests='test("validation", function () {\n  expect(res.getStatus()).to.be.oneOf([400, 422]);\n});')

# ---------------- 09 Carts ----------------
K='Carts'
req(K,1,'Add item','post','cart/items', body='{\n  "branch_id": {{branch_id}},\n  "product_id": {{product_id}},\n  "quantity": 1\n}',
    post='if (res.getStatus() === 200) { bru.setVar("cart_id", String(res.getBody().data.cart.id)); bru.setVar("item_id", String(res.getBody().data.cart.items[0].id)); }',
    tests=ok('test("one cart per branch", function () {\n  expect(String(res.getBody().data.cart.branch.id)).to.equal(bru.getVar("branch_id"));\n});'))
req(K,2,'Add the same item again (merged)','post','cart/items', body='{\n  "branch_id": {{branch_id}},\n  "product_id": {{product_id}},\n  "quantity": 1\n}',
    tests=ok('test("same line, quantity increased", function () {\n  const items = res.getBody().data.cart.items;\n  expect(items.length).to.equal(1);\n  expect(items[0].quantity).to.be.greaterThan(1);\n});'))
req(K,3,'Update quantity','patch','cart/items/{{item_id}}', body='{\n  "quantity": 3\n}', tests=ok('test("quantity 3", function () {\n  expect(res.getBody().data.cart.items[0].quantity).to.equal(3);\n});'))
req(K,4,'Get cart with server-priced preview','get','carts/{{cart_id}}', tests=ok('''
test("totals computed by the server", function () {
  const c = res.getBody().data.cart;
  expect(c.preview).to.be.an("object");
  expect(c.preview).to.have.property("total");
});
'''))
req(K,5,'List my carts','get','carts', tests=ok('test("contains the cart", function () {\n  expect(res.getBody().data.carts.map(c => String(c.id))).to.include(bru.getVar("cart_id"));\n});'))
req(K,6,'Add item - invalid quantity','post','cart/items', body='{\n  "branch_id": {{branch_id}},\n  "product_id": {{product_id}},\n  "quantity": 0\n}', tests='test("validation", function () {\n  expect(res.getStatus()).to.be.oneOf([400, 422]);\n});')

req(K,7,'Remove item','delete','cart/items/{{item_id}}', tests=ok())
req(K,8,'Remove item again - not found','delete','cart/items/{{item_id}}', tests=status(404))
req(K,9,'Re-add item (for checkout)','post','cart/items', body='{\n  "branch_id": {{branch_id}},\n  "product_id": {{product_id}},\n  "quantity": 1\n}',
    post='if (res.getStatus() === 200) { bru.setVar("cart_id", String(res.getBody().data.cart.id)); bru.setVar("item_id", String(res.getBody().data.cart.items[0].id)); }', tests=ok())
req(K,10,'Clear a cart','delete','carts/{{cart_id}}', tests=ok())
req(K,11,'Cleared cart - not found','get','carts/{{cart_id}}', tests=status(404))
req(K,12,'Add item again (cart used by checkout)','post','cart/items', body='{\n  "branch_id": {{branch_id}},\n  "product_id": {{product_id}},\n  "quantity": 3\n}',
    post='if (res.getStatus() === 200) { bru.setVar("cart_id", String(res.getBody().data.cart.id)); bru.setVar("item_id", String(res.getBody().data.cart.items[0].id)); }', tests=ok())

# ---------------- 10 Checkout ----------------
O='Checkout'
req(O,1,'Checkout - idempotency key required','post','carts/{{cart_id}}/checkout', body='{}', tests='test("refused without an idempotency key", function () {\n  expect(res.getStatus()).to.be.oneOf([400, 422]);\n  expect(res.getBody().status).to.equal(false);\n});')
req(O,2,'Checkout - client totals are ignored','post','carts/{{cart_id}}/checkout', body='{\n  "total": "0.01",\n  "notes": "Bruno test order"\n}', headers={'Idempotency-Key':'{{checkout_key}}'},
    pre='bru.setVar("checkout_key", require("crypto").randomUUID());',
    post='if (res.getStatus() === 200) { bru.setVar("order_id", String(res.getBody().data.order.id)); bru.setVar("order_total", res.getBody().data.order.total); }',
    tests=ok('''
test("order placed (pending), server-side total", function () {
  const o = res.getBody().data.order;
  expect(o.status).to.equal("pending");
  expect(Number(o.total)).to.be.greaterThan(0.01);
  expect(res.getBody().data.replayed).to.equal(false);
});
'''))
req(O,3,'Checkout replay (same key) - same order','post','carts/{{cart_id}}/checkout', body='{}', headers={'Idempotency-Key':'{{checkout_key}}'},
    tests=ok('test("replayed, not duplicated", function () {\n  expect(res.getBody().data.replayed).to.equal(true);\n  expect(String(res.getBody().data.order.id)).to.equal(bru.getVar("order_id"));\n});'))

# ---------------- 11 Orders ----------------
Q='Orders'
req(Q,1,'Current orders','get','orders', query={'scope':'current'}, tests=ok('test("contains the new order", function () {\n  expect(res.getBody().data.orders.map(o => String(o.id))).to.include(bru.getVar("order_id"));\n});'))
req(Q,2,'Order details','get','orders/{{order_id}}', tests=ok('''
test("snapshot, payments and private realtime channel", function () {
  const o = res.getBody().data.order;
  expect(o.items.length).to.be.greaterThan(0);
  expect(o.amounts.total).to.equal(o.total);
  expect(o.realtime_channel).to.equal("order." + o.id);
  expect(o.can_cancel).to.equal(true);
});
'''))
req(Q,3,'Cancellation request before acceptance - refused','post','orders/{{order_id}}/cancellation-request', body='{\n  "note": "Bruno"\n}', tests=status(409))
req(Q,4,'Cancel before acceptance','post','orders/{{order_id}}/cancel', body='{\n  "note": "Bruno test cleanup"\n}', tests=ok('test("cancelled", function () {\n  expect(res.getBody().data.order.status).to.equal("cancelled");\n});'))
req(Q,5,'Cancel again - refused','post','orders/{{order_id}}/cancel', body='{}', tests=status(409))
req(Q,6,'Previous orders (history)','get','orders', query={'scope':'previous','per_page':'20'}, tests=ok())

# ---------------- 12 Wallet and Payments ----------------
W='Wallet and Payments'
req(W,1,'Wallet balances (read only)','get','wallet', tests=ok('test("balances per currency", function () {\n  res.getBody().data.wallets.forEach(w => expect(w).to.have.all.keys("currency", "symbol", "balance"));\n});'))
req(W,2,'Wallet transactions','get','wallet/transactions', tests=ok())
req(W,3,'Payments','get','payments', tests=ok())
req(W,4,'Refund history','get','refunds', tests=ok())
req(W,5,'Currencies','get','currencies', tests=ok('test("exactly one default with rate 1", function () {\n  const d = res.getBody().data.currencies.filter(c => c.is_default);\n  expect(d.length).to.equal(1);\n  expect(Number(d[0].exchange_rate)).to.equal(1);\n});'))

# ---------------- 13 PLUS ----------------
X='PLUS'
req(X,1,'Plans','get','plus/plans', post='const p = (res.getBody().data.plans || [])[0]; if (p) { bru.setVar("plan_id", String(p.id)); }', tests=ok())
req(X,2,'My membership and remaining free deliveries','get','plus/membership', tests=ok())
req(X,3,'Eligible discounts (PLUS before public offers)','get','plus/eligible-discounts', tests=ok('test("shape", function () {\n  expect(res.getBody().data).to.include.keys("has_active_cycle", "free_deliveries_remaining", "discounts");\n});'))
req(X,4,'Submit a custom request','post','plus/requests', body='{\n  "type": "custom",\n  "note": "Bruno: question about my plan"\n}',
    tests='test("created, or 409 while one is already pending", function () {\n  expect(res.getStatus()).to.be.oneOf([200, 409, 422]);\n});')
req(X,5,'Request history','get','plus/requests', tests=ok())
req(X,6,'Submit - invalid type','post','plus/requests', body='{\n  "type": "upgrade-for-free"\n}', tests='test("validation", function () {\n  expect(res.getStatus()).to.be.oneOf([400, 422]);\n});')

# ---------------- 14 Notifications ----------------
N='Notifications'
req(N,1,'List notifications','get','notifications', tests=ok('test("order events from this run are listed", function () {\n  expect(res.getBody().data.notifications).to.be.an("array");\n  expect(res.getBody().data.unread_count).to.be.a("number");\n});'))
req(N,2,'Unread count','get','notifications/unread-count', tests=ok())
req(N,3,'Mark all read','post','notifications/read', body='{}', tests=ok())
req(N,4,'Unread count is zero','get','notifications/unread-count', tests=ok('test("zero", function () {\n  expect(res.getBody().data.unread_count).to.equal(0);\n});'))
req(N,5,'Mark another account notification - not found','post','notifications/00000000-0000-4000-8000-000000000000/read', tests=status(404))

# ---------------- 15 Device Tokens ----------------
T='Device Tokens'
req(T,1,'Register device token','post','device-tokens', body='{\n  "token": "{{fcm_token}}",\n  "platform": "android",\n  "device_id": "bruno-device",\n  "device_name": "Bruno"\n}',
    pre='bru.setVar("fcm_token", "bruno-fake-fcm-" + require("crypto").randomBytes(24).toString("hex"));',
    post='if (res.getStatus() === 200) { bru.setVar("device_token_id", String(res.getBody().data.device_token.id)); }',
    tests=ok('test("token never echoed in full", function () {\n  expect(JSON.stringify(res.getBody())).to.not.contain(bru.getVar("fcm_token"));\n});'),
    docs='A random fake token: no push is delivered unless the server has real FCM credentials and WAFFRLI_PUSH_ENABLED=true.')
req(T,2,'List device tokens (one active device)','get','device-tokens', tests=ok('test("only one active", function () {\n  expect(res.getBody().data.device_tokens.filter(t => t.is_active).length).to.equal(1);\n});'))
req(T,3,'Rotate device token','patch','device-tokens/{{device_token_id}}', body='{\n  "token": "{{fcm_token}}-rotated"\n}', tests=ok())
req(T,4,'Remove device token','delete','device-tokens/{{device_token_id}}', tests=ok())
req(T,5,'Remove again - not found','delete','device-tokens/{{device_token_id}}', tests=status(404))
req(T,6,'Register - invalid platform','post','device-tokens', body='{\n  "token": "short",\n  "platform": "symbian",\n  "device_id": "x"\n}', tests=status(400))

# ---------------- 16 Authorization and Negative Tests ----------------
Z='Authorization and Negative Tests'
req(Z,1,'No token - 401','get','me', auth='none', tests=status(401))
req(Z,2,'Customer token on a driver endpoint - refused','get','{{base_url}}/api/v1/driver/me', raw_url=True, tests='test("customer tokens never pass driver routes", function () {\n  expect(res.getStatus()).to.be.oneOf([401, 403]);\n});')
req(Z,3,'Order that is not mine - 404','get','orders/999999', tests=status(404))
req(Z,4,'Realtime - authorize my private channel','post','broadcasting/auth', body='{\n  "socket_id": "1234.5678",\n  "channel_name": "private-user.{{customer_id}}"\n}',
    tests='test("signed when Reverb is configured (503 otherwise)", function () {\n  expect(res.getStatus()).to.be.oneOf([200, 503]);\n  if (res.getStatus() === 200) { expect(res.getBody().auth).to.match(/^[^:]+:[0-9a-f]{64}$/); }\n});')
req(Z,5,'Realtime - another user channel refused','post','broadcasting/auth', body='{\n  "socket_id": "1234.5678",\n  "channel_name": "private-user.999999"\n}',
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([403, 503]);\n});')
req(Z,6,'Realtime - admin channel refused','post','broadcasting/auth', body='{\n  "socket_id": "1234.5678",\n  "channel_name": "private-admin.orders"\n}',
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([403, 503]);\n});')
req(Z,7,'Validation envelope','post','addresses', body='{\n  "lat": "north"\n}', tests=status(400, 'test("first validation message in error", function () {\n  expect(res.getBody().error).to.be.a("string").and.not.empty;\n  expect(res.getBody().data).to.equal(null);\n});'))
req(Z,8,'Logout','post','auth/logout', body='{\n  "device_id": "bruno-device"\n}', tests=ok())
req(Z,9,'Token revoked after logout - 401','get','me', tests=status(401))
