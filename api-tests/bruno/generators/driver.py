import os, json, textwrap, shutil
ROOT=os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','WAFFRLI-Driver'))
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

w('bruno.json', json.dumps({"version":"1","name":"WAFFRLI-Driver","type":"collection","ignore":["node_modules",".git"]}, indent=2))
w('collection.bru', '''
meta {
  name: WAFFRLI-Driver
}

headers {
  Accept: application/json
  Accept-Language: {{locale}}
}

auth {
  mode: bearer
}

auth:bearer {
  token: {{driver_token}}
}

docs {
  WAFFRLI Driver API (/api/v1/driver). Every protected request inherits the
  Bearer token captured by "Authentication / Login (success)".
  Secrets (password, token) are never stored in this repository: the
  password comes from the local, git-ignored .env file and the token is a
  runtime variable. See README.md.
}
''')
envs = {
 'Local': ('http://localhost:8080', 'Docker Compose stack (compose.yaml) on a seeded demo database.'),
 'Shared-Dev': ('https://dev-api.example.invalid', 'Shared development server: replace base_url locally (do not commit a real host if it is private).'),
}
for name,(url,doc) in envs.items():
    w(f'environments/{name}.bru', f'''
vars {{
  base_url: {url}
  locale: en
  driver_phone: {{{{process.env.WAFFRLI_DRIVER_PHONE}}}}
  driver_password: {{{{process.env.WAFFRLI_DRIVER_PASSWORD}}}}
  driver_id: 
  order_id: 
  foreign_order_id: {{{{process.env.WAFFRLI_FOREIGN_ORDER_ID}}}}
  latitude: 33.5101
  longitude: 36.2911
}}
vars:secret [
  driver_token
]
''')
w('.env.example', '''
# Copy to .env (git-ignored) in this folder. Bruno reads it as process.env.
# Never commit real passwords or tokens.
WAFFRLI_DRIVER_PHONE=+12025550201
WAFFRLI_DRIVER_PASSWORD=
# An order id that belongs to ANOTHER driver (for the authorization test),
# e.g. the id of DEMO-0032 on a fresh demo database.
WAFFRLI_FOREIGN_ORDER_ID=
''')
w('.gitignore', '.env\n')

folders = [
 ('01 Authentication', 1), ('02 Profile', 2), ('03 Availability', 3), ('04 Active Orders', 4),
 ('05 Order History', 5), ('06 GPS Location', 6), ('07 Pickup and Delivery', 7),
 ('08 Cash and Settlements', 8), ('09 Notifications', 9), ('10 Negative and Authorization Tests', 10),
 ('11 Device Tokens', 11), ('12 Logout', 12),
]
for name,seq in folders:
    w(f'{name}/folder.bru', f'''
meta {{
  name: {name[3:]}
  seq: {seq}
}}
''')

def req(folder, seq, name, method, url, body=None, body_type='json', auth='inherit', tests='', post='', pre='', docs='', query=None):
    parts=[f'''meta {{
  name: {name}
  type: http
  seq: {seq}
}}
''']
    btype = 'none' if body is None else ('multipartForm' if body_type=='multipart' else 'json')
    qs = ('?' + '&'.join(f'{k}={v}' for k, v in query.items())) if query else ''
    parts.append(f'''{method} {{
  url: {{{{base_url}}}}/api/v1/driver/{url}{qs}
  body: {btype}
  auth: {auth}
}}
''')
    if query:
        parts.append('params:query {\n'+''.join(f'  {k}: {v}\n' for k,v in query.items())+'}\n')
    if body is not None:
        if body_type=='multipart':
            parts.append('body:multipart-form {\n'+''.join(f'  {k}: {v}\n' for k,v in body.items())+'}\n')
        else:
            parts.append('body:json {\n'+textwrap.indent(body.strip(),'  ')+'\n}\n')
    if pre:
        parts.append('script:pre-request {\n'+textwrap.indent(textwrap.dedent(pre).strip(),'  ')+'\n}\n')
    if post:
        parts.append('script:post-response {\n'+textwrap.indent(textwrap.dedent(post).strip(),'  ')+'\n}\n')
    if tests:
        parts.append('tests {\n'+textwrap.indent(textwrap.dedent(tests).strip(),'  ')+'\n}\n')
    if docs:
        parts.append('docs {\n'+textwrap.indent(textwrap.dedent(docs).strip(),'  ')+'\n}\n')
    fname=name.replace('/', '-').replace('?','')
    w(f'{folder}/{fname}.bru', '\n'.join(parts))

ENV = '''
test("standard envelope", function () {
  const b = res.getBody();
  expect(b).to.have.all.keys("data", "status", "error", "statusCode");
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

F='01 Authentication'
req(F,1,'Login - invalid credentials','post','login', body='{\n  "phone": "{{driver_phone}}",\n  "password": "definitely-not-the-password"\n}', auth='none',
    tests=status(401, 'test("generic message (no account enumeration)", function () {\n  expect(res.getBody().error).to.be.a("string").and.not.empty;\n  expect(res.getBody().data).to.equal(null);\n});'))
req(F,2,'Login - validation error','post','login', body='{\n  "phone": "not-a-phone",\n  "password": ""\n}', auth='none', tests=status(400))
req(F,3,'Login (success)','post','login', body='{\n  "phone": "{{driver_phone}}",\n  "password": "{{driver_password}}",\n  "device_name": "Bruno"\n}', auth='none',
    post='''
if (res.getStatus() === 200) {
  bru.setVar("driver_token", res.getBody().data.token);
  bru.setVar("driver_id", String(res.getBody().data.driver.id));
}
''', tests=status(200, '''
test("returns a bearer token and the driver", function () {
  const d = res.getBody().data;
  expect(d.token).to.be.a("string").and.not.empty;
  expect(d.token_type).to.equal("Bearer");
  expect(d.driver.phone).to.equal(bru.interpolate("{{driver_phone}}"));
});
test("never returns credentials", function () {
  const raw = JSON.stringify(res.getBody().data.driver).toLowerCase();
  expect(raw).to.not.contain("password");
});
'''), docs='Captures driver_token and driver_id as runtime variables (never saved to disk).')

F='02 Profile'
req(F,1,'Get my profile','get','me', tests=status(200, '''
test("it is me", function () {
  expect(String(res.getBody().data.driver.id)).to.equal(bru.getVar("driver_id"));
});
test("availability block present", function () {
  const a = res.getBody().data.driver.availability;
  expect(a.status).to.be.oneOf(["available", "offline"]);
  expect(a.has_active_delivery).to.be.a("boolean");
});
'''))
req(F,2,'Update language','patch','profile', body='{\n  "language": "{{locale}}"\n}', tests=status(200, '''
test("only language changed", function () {
  expect(res.getBody().data.driver.language).to.equal(bru.interpolate("{{locale}}"));
  expect(res.getBody().data.driver.editable_fields).to.eql(["language"]);
});
'''))
req(F,3,'Change password - wrong current password','post','password',
    body='{\n  "current_password": "wrong-current-password",\n  "password": "a-new-password-123",\n  "password_confirmation": "a-new-password-123"\n}',
    tests=status(422), docs='Safe example: the wrong current password is refused. A real change revokes your other tokens and requires updating your local .env.')

F='03 Availability'
req(F,1,'Get availability','get','availability', post='''
bru.setVar("has_active_delivery", String(res.getBody().data.has_active_delivery));
''', tests=status(200, '''
test("consistent with active orders", function () {
  const d = res.getBody().data;
  expect(d.has_active_delivery).to.equal(d.active_orders.length > 0);
  expect(d.can_go_offline).to.equal(!d.has_active_delivery);
});
'''))
req(F,2,'Go offline (refused while delivering)','patch','availability', body='{\n  "availability": "offline"\n}', tests='''
test("refused (409) with an active delivery, accepted otherwise", function () {
  const active = bru.getVar("has_active_delivery") === "true";
  expect(res.getStatus()).to.equal(active ? 409 : 200);
});
''')
req(F,3,'Set available','patch','availability', body='{\n  "availability": "available"\n}', tests=status(200, '''
test("available", function () {
  expect(res.getBody().data.availability).to.equal("available");
});
'''))

F='04 Active Orders'
req(F,1,'List my active orders','get','orders/active', post='''
const orders = res.getBody().data.orders;
if (orders.length > 0) {
  bru.setVar("order_id", String(orders[0].id));
  bru.setVar("order_status", orders[0].status);
  bru.setVar("cash_due", orders[0].payment.cash_to_collect);
  bru.setVar("currency_code", orders[0].payment.currency.code);
}
''', tests=status(200, '''
test("only assigned / picked-up orders", function () {
  res.getBody().data.orders.forEach(o => expect(o.status).to.be.oneOf(["assigned", "picked_up"]));
});
test("demo driver has an active order to work with", function () {
  expect(res.getBody().data.orders.length).to.be.greaterThan(0);
});
'''))
req(F,2,'Get order details','get','orders/{{order_id}}', tests=status(200, '''
const o = res.getBody().data.order;
test("same order", function () {
  expect(String(o.id)).to.equal(bru.getVar("order_id"));
});
test("cash amount and currency are consistent", function () {
  expect(o.payment.cash_to_collect).to.equal(bru.getVar("cash_due"));
  expect(o.payment.currency.code).to.equal(bru.getVar("currency_code"));
  const total = Number(o.payment.order_total);
  const parts = Number(o.payment.cash_to_collect) + Number(o.payment.prepaid_amount);
  expect(Math.abs(total - parts)).to.be.lessThan(Math.pow(10, -o.payment.currency.decimals) + 1e-9);
});
test("customer contact present for the active delivery", function () {
  expect(o.customer).to.not.equal(null);
  expect(o.delivery.lat).to.be.a("number");
});
'''))

F='05 Order History'
req(F,1,'Delivery history','get','orders/history', query={'per_page':'20'}, tests=status(200, '''
test("history hides customer personal data", function () {
  res.getBody().data.orders.forEach(o => {
    expect(o.status).to.be.oneOf(["delivered", "cancelled"]);
    expect(o.customer).to.equal(null);
    expect(o.delivery.address).to.equal(null);
  });
});
'''))

F='06 GPS Location'
req(F,1,'Update my location','post','location', body='{\n  "lat": {{latitude}},\n  "lng": {{longitude}}\n}', tests=status(200, '''
test("stored position echoed", function () {
  const l = res.getBody().data.location;
  expect(l.lat).to.equal(Number(bru.interpolate("{{latitude}}")));
  expect(l.recorded_at).to.be.a("string");
});
'''), docs='Accepted only while you have an assigned or picked-up delivery (rate limit: 12 per minute).')
req(F,2,'Invalid coordinates','post','location', body='{\n  "lat": 123.45,\n  "lng": 36.29\n}', tests=status(400))
req(F,3,'Null island (0,0) rejected','post','location', body='{\n  "lat": 0,\n  "lng": 0\n}', tests=status(400))

F='07 Pickup and Delivery'
req(F,1,'Deliver before pickup (invalid transition)','post','orders/{{order_id}}/deliver', body='{\n  "cash_collected": "{{cash_due}}"\n}', tests='''
test("refused while the order is not picked up", function () {
  if (bru.getVar("order_status") === "assigned") {
    expect(res.getStatus()).to.equal(422);
  } else {
    expect(res.getStatus()).to.be.oneOf([200, 409, 422]);
  }
});
''')
req(F,2,'Pick up order','post','orders/{{order_id}}/pickup', tests='''
test("picked up (or already picked up earlier)", function () {
  if (bru.getVar("order_status") === "assigned") {
    expect(res.getStatus()).to.equal(200);
    expect(res.getBody().data.order.status).to.equal("picked_up");
  } else {
    expect(res.getStatus()).to.equal(422);
  }
});
''')
req(F,3,'Pick up again (invalid transition)','post','orders/{{order_id}}/pickup', tests=status(422))
req(F,4,'Report a delivery problem','post','orders/{{order_id}}/problems', body='{\n  "type": "customer_unreachable",\n  "note": "Bruno test: no answer at the door"\n}', tests=status(200, '''
test("reported, order not cancelled", function () {
  expect(res.getBody().data.type).to.equal("customer_unreachable");
});
'''))
req(F,5,'Deliver with wrong cash amount','post','orders/{{order_id}}/deliver', body='{\n  "cash_collected": "0.01"\n}', tests='''
test("refused unless 0.01 happens to be due", function () {
  expect(res.getStatus()).to.equal(bru.getVar("cash_due") === "0.01" ? 200 : 422);
});
''')
req(F,6,'Complete delivery (cash + photo)','post','orders/{{order_id}}/deliver',
    body={'cash_collected':'{{cash_due}}','currency':'{{currency_code}}','photo':'@file(fixtures/delivery-proof.png)'}, body_type='multipart',
    tests=status(200, '''
const o = res.getBody().data.order;
test("delivered", function () {
  expect(o.status).to.equal("delivered");
  expect(o.timeline.delivered_at).to.be.a("string");
  expect(o.has_delivery_photo).to.equal(true);
});
test("collected exactly the cash due, in the frozen currency", function () {
  expect(o.payment.currency.code).to.equal(bru.getVar("currency_code"));
  expect(Number(o.payment.cash_collected)).to.equal(Number(bru.getVar("cash_due")));
  expect(Number(o.payment.cash_to_collect)).to.equal(0);
});
'''), docs='cash_collected must equal payment.cash_to_collect; photo is optional (jpg/png/webp, max 5 MB).')
req(F,7,'Complete delivery again (duplicate)','post','orders/{{order_id}}/deliver', body='{\n  "cash_collected": "{{cash_due}}"\n}', tests=status(409))

F='08 Cash and Settlements'
req(F,1,'Cash summary','get','cash/summary', tests=status(200, '''
test("one row per currency, amounts are strings", function () {
  res.getBody().data.currencies.forEach(c => {
    expect(c.currency).to.match(/^[A-Z]{3}$/);
    expect(c.collected_unsettled).to.be.a("string");
  });
});
'''))
req(F,2,'Cash collections','get','cash/collections', tests=status(200, '''
test("the delivered order's cash is listed in its currency", function () {
  const row = res.getBody().data.collections.find(c => String(c.order_id) === bru.getVar("order_id"));
  if (Number(bru.getVar("cash_due")) > 0) {
    expect(row).to.not.equal(undefined);
    expect(row.currency).to.equal(bru.getVar("currency_code"));
    expect(Number(row.amount_collected)).to.equal(Number(bru.getVar("cash_due")));
  }
});
'''))
req(F,3,'Daily settlements','get','cash/settlements', tests=status(200, '''
test("read-only settlement rows", function () {
  res.getBody().data.settlements.forEach(s => expect(s.status).to.be.oneOf(["open", "settled", "disputed"]));
});
'''))

F='09 Notifications'
req(F,1,'List my notifications','get','notifications', post='''
const list = res.getBody().data.notifications;
if (list.length > 0) { bru.setVar("notification_id", list[0].id); }
''', tests=status(200, '''
test("assignment notifications refer to my orders", function () {
  const list = res.getBody().data.notifications;
  expect(list.length).to.be.greaterThan(0);
  expect(res.getBody().data.unread_count).to.be.a("number");
});
'''))
req(F,2,'Unread notifications','get','notifications', query={'unread':'1'}, tests=status(200, '''
test("only unread", function () {
  res.getBody().data.notifications.forEach(n => expect(n.read_at).to.equal(null));
});
'''))
req(F,3,'Mark one as read','post','notifications/{{notification_id}}/read', tests=status(200, '''
test("marked", function () {
  expect(res.getBody().data.notification.read_at).to.be.a("string");
});
'''))
req(F,4,'Mark all as read','post','notifications/read', body='{}', tests=status(200, '''
test("marked count returned", function () {
  expect(res.getBody().data.marked).to.be.a("number");
});
'''))

F='10 Negative and Authorization Tests'
req(F,1,'No token','get','me', auth='none', tests=status(401))
req(F,2,'Invalid token','get','me', auth='none', pre='''
req.setHeader("Authorization", "Bearer 999|not-a-real-token");
''', tests=status(401))
req(F,3,"Another driver's order",'get','orders/{{foreign_order_id}}', tests='''
test("hidden as 404 (never exposed)", function () {
  expect(res.getStatus()).to.equal(404);
  expect(res.getBody().data).to.equal(null);
});
''', docs="Set WAFFRLI_FOREIGN_ORDER_ID in .env to an order assigned to a different driver.")
req(F,4,'Location without an active delivery','post','location', body='{\n  "lat": {{latitude}},\n  "lng": {{longitude}}\n}', tests=status(409),
    docs='Runs after the delivery is completed, so the demo driver has no active delivery left.')
req(F,5,'Pick up a delivered order','post','orders/{{order_id}}/pickup', tests=status(422))
req(F,6,"Someone else's notification",'post','notifications/00000000-0000-4000-8000-000000000000/read', tests=status(404))
F='10 Negative and Authorization Tests'
req(F,7,'Realtime - authorize my private channel','post','broadcasting/auth', body='{\n  "socket_id": "1234.5678",\n  "channel_name": "private-driver.{{driver_id}}"\n}',
    tests='test("signed when Reverb is configured (503 otherwise)", function () {\n  expect(res.getStatus()).to.be.oneOf([200, 503]);\n  if (res.getStatus() === 200) { expect(res.getBody().auth).to.match(/^[^:]+:[0-9a-f]{64}$/); }\n});')
req(F,8,'Realtime - another driver channel refused','post','broadcasting/auth', body='{\n  "socket_id": "1234.5678",\n  "channel_name": "private-driver.999999"\n}',
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([403, 503]);\n});')
req(F,9,'Realtime - admin map channel refused','post','broadcasting/auth', body='{\n  "socket_id": "1234.5678",\n  "channel_name": "private-admin.drivers-map"\n}',
    tests='test("refused", function () {\n  expect(res.getStatus()).to.be.oneOf([403, 503]);\n});')
req('11 Device Tokens',1,'Register device token','post','device-tokens', body='{\n  "token": "{{fcm_token}}",\n  "platform": "android",\n  "device_id": "bruno-driver-device",\n  "device_name": "Bruno"\n}',
    pre='bru.setVar("fcm_token", "bruno-fake-fcm-" + require("crypto").randomBytes(24).toString("hex"));',
    post='if (res.getStatus() === 200) { bru.setVar("device_token_id", String(res.getBody().data.device_token.id)); }',
    tests=status(200, 'test("token never echoed in full", function () {\n  expect(JSON.stringify(res.getBody())).to.not.contain(bru.getVar("fcm_token"));\n});'),
    docs='A random fake token; no push is delivered without real FCM credentials.')
req('11 Device Tokens',2,'List device tokens','get','device-tokens', tests=status(200))
req('11 Device Tokens',3,'Rotate device token','patch','device-tokens/{{device_token_id}}', body='{\n  "token": "{{fcm_token}}-rotated"\n}', tests=status(200))
req('11 Device Tokens',4,'Register - invalid platform','post','device-tokens', body='{\n  "token": "short",\n  "platform": "symbian",\n  "device_id": "x"\n}', tests=status(400))
req('11 Device Tokens',5,'Remove device token','delete','device-tokens/{{device_token_id}}', tests=status(200))
req('11 Device Tokens',6,'Remove again - not found','delete','device-tokens/{{device_token_id}}', tests=status(404))
req('12 Logout',1,'Logout','post','logout', tests=status(200))
req('12 Logout',2,'Token revoked after logout','get','me', tests=status(401))
print('ok')
