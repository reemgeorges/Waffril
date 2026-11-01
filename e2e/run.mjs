#!/usr/bin/env node
// WAFFRLI end-to-end run against the LOCAL Docker stack (compose.yaml).
//
//   node e2e/run.mjs            (configure e2e/.env first: see e2e/README.md)
//
// Real HTTP to the API, a real WebSocket client on the ONE Reverb server, a
// real Chromium on the Filament panel. SMS and push use the local FAKE
// providers: nothing is sent to a real SMS, WhatsApp or FCM service, and the
// report says so. It writes e2e/results/latest.{md,json} (git-ignored).
//
// It creates real records in the DEVELOPMENT database (orders, payments,
// notifications) like a tester would; never point it at shared/staging/prod.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { Api, ReverbClient, Report, ROOT, assert, readEnv, sleep, uuid } from './lib.mjs';

const app = readEnv(path.join(ROOT, '.env'));
const cfg = { ...readEnv(path.join(ROOT, 'e2e', '.env')), ...process.env };
const BASE = cfg.E2E_BASE_URL || `http://localhost:${app.APP_PORT || 8080}`;
const WS = cfg.E2E_REVERB_URL || `ws://localhost:${app.REVERB_PUBLIC_PORT || 8085}/app/${app.REVERB_APP_KEY}?protocol=7&client=waffrli-e2e&version=1.0`;
const PEEK = app.WAFFRLI_DEV_OTP_PEEK_TOKEN;
const need = ['E2E_ADMIN_EMAIL', 'E2E_ADMIN_PASSWORD', 'E2E_DRIVER_PHONE', 'E2E_DRIVER_PASSWORD', 'E2E_CUSTOMER_PHONE', 'E2E_OTHER_CUSTOMER_PHONE'].filter((k) => !cfg[k]);
if (need.length || !app.REVERB_APP_KEY || !PEEK) {
  console.error(`Missing configuration: ${need.join(', ')}${app.REVERB_APP_KEY ? '' : ' REVERB_APP_KEY (.env)'}${PEEK ? '' : ' WAFFRLI_DEV_OTP_PEEK_TOKEN (.env)'}. See e2e/README.md.`);
  process.exit(2);
}
const LOG = path.join(ROOT, 'storage', 'logs', 'laravel.log');
const report = new Report();
const S = {}; // shared state between steps
const anon = new Api(BASE);
const sha16 = async (t) => (await import('node:crypto')).createHash('sha256').update(t).digest('hex').slice(0, 16);
const logSize = () => (fs.existsSync(LOG) ? fs.statSync(LOG).size : 0);
const logSince = (offset) => (fs.existsSync(LOG) ? fs.readFileSync(LOG).subarray(offset).toString('utf8') : '');

async function customerLogin(phone, device) {
  const otp = await anon.post('/api/v1/customer/auth/otp/request', { phone });
  assert(otp.status === 200, `OTP request for the demo phone → ${otp.status} ${otp.body?.error ?? ''} (OTP limits: 5 sends/hour per phone, 60 s cooldown)`);
  const peek = await anon.get(`/api/v1/dev/otp?phone=${encodeURIComponent(phone)}`, { 'X-Dev-Token': PEEK });
  assert(peek.status === 200, `dev OTP peek → ${peek.status} (WAFFRLI_SMS_DRIVER=fake and the peek token are required)`);
  const data = await anon.ok('POST', '/api/v1/customer/auth/otp/verify', { phone, code: peek.body.data.code, device_id: device, device_name: 'E2E' });
  assert(data.status === 'logged_in' && data.token, 'expected a logged-in customer');
  return { api: new Api(BASE, { token: data.token }), id: data.user.id, token: data.token };
}
const customerWs = (token, label) => new ReverbClient(WS, (socket_id, channel_name) => new Api(BASE, { token }).post('/api/v1/customer/broadcasting/auth', { socket_id, channel_name }), label);
const driverWs = (token, label) => new ReverbClient(WS, (socket_id, channel_name) => new Api(BASE, { token }).post('/api/v1/driver/broadcasting/auth', { socket_id, channel_name }), label);
const statusEvent = (status, orderId) => (e) => e.event === 'order.status_changed' && e.data?.order_id === orderId && e.data?.status === status;

/** Fill a cart (a product that needs options when one exists) and return {cart, product, options}. */
async function fillCart(api, { wantOptions = true, quantity = 2 } = {}) {
  const branches = (await api.ok('GET', '/api/v1/customer/branches?per_page=50')).branches.filter((b) => b.is_open !== false);
  for (const branch of branches) {
    const products = (await api.ok('GET', `/api/v1/customer/branches/${branch.id}/products?per_page=100`)).products.filter((p) => p.is_available);
    const candidates = wantOptions ? [...products.filter((p) => p.requires_options), ...products] : products.filter((p) => !p.requires_options);
    for (const p of candidates) {
      const detail = (await api.ok('GET', `/api/v1/customer/branches/${branch.id}/products/${p.id}`)).product;
      const options = [];
      for (const g of detail.option_groups) {
        const avail = g.options.filter((o) => o.is_available);
        if (avail.length < g.min_select) { options.length = 0; break; }
        avail.slice(0, Math.max(g.min_select, g.kind === 'choice' ? 0 : 0)).forEach((o) => options.push({ id: o.id }));
      }
      if (detail.option_groups.some((g) => g.min_select > 0) && !options.length) continue;
      let q = quantity, cart;
      for (; q <= 8; q++) {
        const r = await api.post('/api/v1/customer/cart/items', { branch_id: branch.id, product_id: p.id, quantity: q, options });
        if (r.status !== 200) break;
        cart = r.body.data.cart;
        const preview = (await api.ok('GET', `/api/v1/customer/carts/${cart.id}`)).cart;
        if (preview.preview && !preview.issue) return { cart: preview, product: detail, options, branch };
        await api.delete(`/api/v1/customer/carts/${cart.id}`);
        q = Math.max(q, 3);
      }
    }
  }
  throw new Error('No orderable product found in the delivery city (demo data missing?).');
}

async function main() {
  console.log(`WAFFRLI E2E → ${BASE} | Reverb ${WS.replace(/\/app\/[^?]+/, '/app/<key>')}`);

  // ---------------------------------------------------------------- setup
  await report.step('0.1', 'Stack reachable (/up, /admin/login, Reverb)', 'http', async () => {
    assert((await fetch(BASE + '/up')).status === 200, '/up');
    assert((await fetch(BASE + '/admin/login')).status === 200, '/admin/login');
    const probe = new ReverbClient(WS, async () => ({ status: 0 }), 'probe');
    const sid = await probe.connect(); probe.close();
    const tryOrigin = async (origin) => { const c = new ReverbClient(WS, async () => ({ status: 0 }), 'origin-probe', origin); const r = await c.connect(4000).then(() => 'accepted', (e) => (e.message.includes('4009') ? 'refused' : e.message)); c.close(); return r; };
    const none = await tryOrigin(null), evil = await tryOrigin('https://evil.example'), lookalike = await tryOrigin('http://localhost.evil.example');
    assert(evil === 'refused' && lookalike === 'refused', `foreign browser origins must be refused (got ${evil}, ${lookalike})`);
    if (none !== 'accepted') return { skip: `native clients (no Origin) are refused: set REVERB_ALLOW_ORIGINLESS_CLIENTS=true (got ${none})` };
    return `Reverb socket_id ${sid}; Origin localhost accepted; no Origin (native app) accepted; https://evil.example and localhost.evil.example refused (4009)`;
  });

  // ------------------------------------------------- 1. customer authentication
  await report.step('1', 'Customer authenticates (phone OTP via fake SMS + dev peek)', 'http+fake-provider', async () => {
    S.c = await customerLogin(cfg.E2E_CUSTOMER_PHONE, 'e2e-' + Date.now());
    S.loginAt = Date.now();
    return `customer #${S.c.id}; the OTP was read from the development-only peek route (fake SMS driver), never from a real SMS`;
  });
  if (!S.c) return;
  const C = S.c.api;

  const Cu = () => S.c.api;

  // ------------------------------------------------- 2. address and city
  await report.step('2', 'Address and delivery city', 'http', async () => {
    const ctx = await Cu().ok('GET', '/api/v1/customer/context');
    assert(ctx.selected_address && ctx.delivery_city, 'the demo customer needs a default address with a city');
    const cities = (await Cu().ok('GET', '/api/v1/customer/cities')).cities;
    assert(cities.some((c) => c.id === ctx.delivery_city.id), 'delivery city is an active city');
    S.city = ctx.delivery_city; S.currency = ctx.default_currency;
    return `address #${ctx.selected_address.id} in ${ctx.delivery_city.name}; default currency ${ctx.default_currency.code}`;
  });

  // ------------------------------------------------- E1. scheduled order + cancellation + wallet refund
  await report.step('E1', 'Scheduled order paid by wallet, cancelled by the customer, wallet restored', 'http', async () => {
    const wallet = (await Cu().ok('GET', '/api/v1/customer/wallet')).wallets.find((w) => w.currency === S.currency.code);
    if (!wallet || Number(wallet.balance) <= 0) return { skip: `no ${S.currency.code} wallet balance (wallets are only credited by refunds)` };
    const extra = await fillCart(Cu(), { wantOptions: false });
    const at = new Date(Date.now() + 3 * 3600e3).toISOString();
    const r = await Cu().post(`/api/v1/customer/carts/${extra.cart.id}/checkout`, { wallet_amount: '0.50', scheduled_for: at, notes: 'E2E scheduled' }, { 'Idempotency-Key': uuid() });
    assert(r.status === 200, `scheduled checkout → ${r.status} ${r.body?.error}`);
    const o = r.body.data.order;
    assert(o.type === 'scheduled', `type ${o.type}`);
    assert(o.payments.some((p) => p.method === 'wallet' && p.amount === '0.50'), 'wallet part 0.50');
    const before = Number(wallet.balance);
    await Cu().ok('POST', `/api/v1/customer/orders/${o.id}/cancel`, { note: 'E2E cancel' });
    const after = Number((await Cu().ok('GET', '/api/v1/customer/wallet')).wallets.find((w) => w.currency === S.currency.code).balance);
    assert(Math.abs(after - before) < 1e-9, `wallet ${before} → ${after}: the wallet part must be returned`);
    const again = await Cu().post(`/api/v1/customer/orders/${o.id}/cancel`, {});
    assert(again.status === 409, `second cancel → ${again.status}`);
    return `${o.order_number} scheduled for ${o.scheduled_for}; cancelled; wallet back to ${after}; second cancel 409`;
  });

  // ------------------------------------------------- E2. duplicates and concurrency
  await report.step('E2', 'Duplicate and concurrent checkouts', 'http', async () => {
    const dup = await fillCart(Cu(), { wantOptions: false });
    const key = uuid();
    const same = await Promise.all([1, 2, 3, 4].map(() => Cu().post(`/api/v1/customer/carts/${dup.cart.id}/checkout`, {}, { 'Idempotency-Key': key })));
    const ok = same.filter((r) => r.status === 200);
    const ids = [...new Set(ok.map((r) => r.body.data.order.id))];
    assert(ok.length >= 1 && ids.length === 1, `same key → statuses ${same.map((r) => r.status)}; orders ${ids}`);
    const others = same.filter((r) => r.status !== 200).map((r) => r.status);
    assert(others.every((s) => [409, 422, 429].includes(s)), `unexpected statuses ${others}`);
    const replay = await Cu().post(`/api/v1/customer/carts/${dup.cart.id}/checkout`, {}, { 'Idempotency-Key': key });
    assert(replay.status === 200 && replay.body.data.replayed === true && replay.body.data.order.id === ids[0], `replay → ${replay.status}`);
    const second = await Cu().post(`/api/v1/customer/carts/${dup.cart.id}/checkout`, {}, { 'Idempotency-Key': uuid() });
    assert(second.status !== 200, 'a consumed cart cannot be checked out again with a new key');
    await Cu().ok('POST', `/api/v1/customer/orders/${ids[0]}/cancel`, { note: 'E2E duplicate test cleanup' });
    return `4 parallel requests with one key → 1 order (${ok.length}×200, others ${others.join(',') || '-'}); replay returns it; new key on the consumed cart → ${second.status}`;
  });

  // ------------------------------------------------- 3-5. browse, options, cart
  await report.step('3-5', 'Browse branches, product with options, cart with server-priced preview', 'http', async () => {
    S.order1 = await fillCart(Cu(), { wantOptions: true });
    const { cart, product, options } = S.order1;
    assert(cart.items.length === 1, 'one line');
    return `${product.name} (${options.length} option(s): ${product.option_groups.map((g) => g.name).join(', ') || 'none required'}) ×${cart.items[0].quantity} at ${cart.branch.name}; preview total ${cart.preview.total}`;
  });

  // ------------------------------------------------- push tokens (fake FCM)
  await report.step('FCM.1', 'Register a device token (fake FCM transport)', 'http+fake-provider', async () => {
    S.fcm = 'e2e-fake-fcm-' + uuid();
    const t = (await Cu().ok('POST', '/api/v1/customer/device-tokens', { token: S.fcm, platform: 'android', device_id: 'e2e-device', device_name: 'E2E' })).device_token;
    S.fcmId = t.id; S.fcmHash = await sha16(S.fcm); S.logOffset = logSize();
    return `token #${t.id} (hash ${S.fcmHash}); WAFFRLI_PUSH_DRIVER=fake: nothing is sent to Firebase`;
  });

  // ------------------------------------------------- 6. PLUS
  await report.step('6', 'PLUS membership and benefits', 'http', async () => {
    const m = await Cu().ok('GET', '/api/v1/customer/plus/eligible-discounts');
    S.useFree = m.has_active_cycle && m.free_deliveries_remaining > 0;
    return `active cycle: ${m.has_active_cycle}; free deliveries left: ${m.free_deliveries_remaining}; discounts: ${m.discounts.length}`;
  });

  // ------------------------------------------------- 7-8. wallet + cash remainder
  await report.step('7-8', 'Checkout: wallet payment + cash remainder (+ PLUS free delivery)', 'http', async () => {
    const wallet = (await Cu().ok('GET', '/api/v1/customer/wallet')).wallets.find((w) => w.currency === S.currency.code);
    const balance = Number(wallet?.balance ?? 0);
    const body = { notes: 'E2E main order', use_free_delivery: S.useFree };
    if (balance > 0) body.wallet_amount = 'max';
    const o = (await Cu().ok('POST', `/api/v1/customer/carts/${S.order1.cart.id}/checkout`, body, { 'Idempotency-Key': uuid() })).order;
    S.order = o;
    const sum = o.payments.reduce((a, p) => a + Number(p.amount), 0);
    assert(Math.abs(sum - Number(o.total)) < 1e-9, `payments ${sum} ≠ total ${o.total}`);
    const cash = o.payments.find((p) => p.method === 'cash');
    const walletPart = o.payments.find((p) => p.method === 'wallet');
    if (balance > 0) assert(walletPart && cash && Number(cash.amount) > 0, 'expected wallet + cash remainder');
    if (S.useFree) assert(o.amounts.free_delivery_applied === true && o.amounts.delivery_fee === '0.00', 'free delivery applied');
    return `${o.order_number} total ${o.total} ${o.currency?.code ?? ''} = ${o.payments.map((p) => `${p.method} ${p.amount}`).join(' + ')}; free delivery ${o.amounts.free_delivery_applied}${balance > 0 ? '' : ' (no wallet balance: cash only)'}`;
  });
  if (!S.order) return;
  const OID = S.order.id;

  // ------------------------------------------------- realtime subscribers (real WebSocket)
  await report.step('WS.1', 'Customer connects to Reverb and subscribes to its order channel', 'ws', async () => {
    S.cws = customerWs(S.c.token, 'customer'); await S.cws.connect();
    const r = await S.cws.subscribe(`private-order.${OID}`);
    assert(r.status === 200, `auth → ${r.status}`);
    return `socket ${S.cws.socketId}: private-order.${OID} subscribed (auth signed by /api/v1/customer/broadcasting/auth)`;
  });

  await report.step('LOGIN.D', 'Driver logs in (phone + password) and is available', 'http', async () => {
    const d = await anon.ok('POST', '/api/v1/driver/login', { phone: cfg.E2E_DRIVER_PHONE, password: cfg.E2E_DRIVER_PASSWORD, device_name: 'E2E' });
    S.d = { api: new Api(BASE, { token: d.token }), id: d.driver.id, token: d.token };
    const active = (await S.d.api.ok('GET', '/api/v1/driver/orders/active')).orders;
    assert(active.length === 0, `driver #${d.driver.id} already has ${active.length} active deliveries: choose an idle demo driver (e2e/README.md)`);
    await S.d.api.ok('PATCH', '/api/v1/driver/availability', { availability: 'available' });
    S.dws = driverWs(d.token, 'driver'); await S.dws.connect();
    const r = await S.dws.subscribe(`private-driver.${d.driver.id}`);
    assert(r.status === 200, `driver channel auth → ${r.status}`);
    return `driver #${d.driver.id} available; private-driver.${d.driver.id} subscribed`;
  });

  await report.step('WS.2', 'Channel authorization denies foreign channels (real auth endpoint + Reverb)', 'ws', async () => {
    S.other = await customerLogin(cfg.E2E_OTHER_CUSTOMER_PHONE, 'e2e-other-' + Date.now());
    S.ows = customerWs(S.other.token, 'other-customer (native, no Origin)'); S.ows.origin = null; await S.ows.connect();
    const own = await S.ows.subscribe(`private-user.${S.other.id}`);
    const foreignOrder = await S.ows.subscribe(`private-order.${OID}`);
    const foreignUser = await S.ows.subscribe(`private-user.${S.c.id}`);
    const adminMap = await S.dws.subscribe('private-admin.drivers-map');
    const forged = await S.ows.subscribeForged(`private-order.${OID}`);
    assert(own.status === 200, `own channel → ${own.status}`);
    assert(foreignOrder.status === 403 && foreignUser.status === 403 && adminMap.status === 403, `foreign: order ${foreignOrder.status}, user ${foreignUser.status}, driver→admin map ${adminMap.status}`);
    assert(forged !== 'subscribed', 'Reverb accepted a forged signature');
    return `native client (no Origin): own channel 200; other customer's order 403, user 403; driver → admin map 403; forged signature → ${forged} by Reverb`;
  });

  // ------------------------------------------------- 9-11. Filament (real browser)
  const require = createRequire(import.meta.url);
  let chromium;
  try { ({ chromium } = require('playwright')); } catch { /* reported below */ }
  if (!chromium) {
    await report.step('9-11', 'Filament steps', 'browser', async () => ({ skip: 'Playwright is not installed (npm i -D playwright && npx playwright install chromium)' }));
    return;
  }
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  const shots = path.join(ROOT, 'e2e', 'results'); fs.mkdirSync(shots, { recursive: true });
  // The panel's own Echo client (real browser WebSocket); re-armed after every page load.
  const listenAdmin = async () => {
    await page.waitForFunction(() => window.Echo && window.Echo.connector.pusher.connection.state === 'connected', null, { timeout: 20000 });
    await page.evaluate(() => {
      window.__e2e = [];
      window.Echo.private('admin.orders').listen('.order.status_changed', (e) => window.__e2e.push(['status', e]));
      window.Echo.private('admin.drivers-map').listen('.driver.location', (e) => window.__e2e.push(['location', e]));
    });
    await page.waitForFunction(() => ['private-admin.orders', 'private-admin.drivers-map'].every((n) => window.Echo.connector.pusher.channel(n)?.subscribed), null, { timeout: 15000 });
  };
  try {
    await report.step('9', 'Order visible in Filament (admin session login)', 'browser', async () => {
      await page.goto(BASE + '/admin/login');
      await page.fill('input[type=email]', cfg.E2E_ADMIN_EMAIL);
      await page.fill('input[type=password]', cfg.E2E_ADMIN_PASSWORD);
      await page.press('input[type=password]', 'Enter');
      await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 });
      await page.goto(BASE + '/admin/orders');
      await page.getByText(S.order.order_number).first().waitFor({ timeout: 15000 });
      await page.goto(`${BASE}/admin/orders/${OID}`);
      await page.waitForFunction(() => window.Echo && window.Echo.connector.pusher.connection.state === 'connected', null, { timeout: 20000 });
      await listenAdmin();
      await page.screenshot({ path: path.join(shots, '09-filament-order.png') });
      return `${S.order.order_number} listed and opened; the panel's own Echo client subscribed to private-admin.orders and private-admin.drivers-map`;
    });

    await report.step('10', 'Admin accepts the order in Filament → customer and admin receive the event', 'browser+ws', async () => {
      await page.locator(`[wire\\:click*="mountAction('accept'"]`).first().click();
      await page.locator('.fi-modal.fi-modal-open button[type=submit]').last().click();
      const ev = await S.cws.waitFor(statusEvent('accepted', OID), 15000, 'order.status_changed(accepted)');
      await page.waitForFunction((id) => window.__e2e.some(([k, e]) => k === 'status' && e.order_id === id && e.status === 'accepted'), OID, { timeout: 10000 });
      const o = (await Cu().ok('GET', `/api/v1/customer/orders/${OID}`)).order;
      assert(o.status === 'accepted', `API status ${o.status}`);
      return `customer socket got order.status_changed(accepted) after ${ev.latency} ms; admin browser too`;
    });

    await report.step('FCM.2', 'Push for "accepted" handled by the queue worker (fake FCM, observed in the log by token hash)', 'fake-provider', async () => {
      const t0 = Date.now();
      while (Date.now() - t0 < 15000) {
        if (logSince(S.logOffset).includes(`"token_sha256":"${S.fcmHash}"`)) return `queue worker → SendPushMessage → fake transport: push.fake_delivered for token hash ${S.fcmHash}. NOT delivered to a real device.`;
        await sleep(500);
      }
      throw new Error('no push.fake_delivered entry for the token (queue worker running? WAFFRLI_PUSH_ENABLED=true?)');
    });

    await report.step('11', 'Admin assigns the driver in Filament → driver receives the event', 'browser+ws', async () => {
      await page.reload({ waitUntil: 'networkidle' });
      await page.waitForFunction(() => window.Livewire && document.querySelector(`[wire\\:click*="mountAction('assign'"]`), null, { timeout: 15000 });
      await page.locator(`[wire\\:click*="mountAction('assign'"]`).first().click();
      const select = page.locator('select[wire\\:model*="driver_id"]');
      await select.waitFor({ state: 'visible', timeout: 15000 });
      const modal = page.locator('.fi-modal', { has: select });
      const offered = await select.locator('option').evaluateAll((o) => o.map((x) => x.value));
      assert(offered.includes(String(S.d.id)), `driver #${S.d.id} is not among the recommended drivers (${offered.filter(Boolean)})`);
      await select.selectOption(String(S.d.id));
      await modal.locator('button[type=submit]').last().click();
      const ev = await S.dws.waitFor(statusEvent('assigned', OID), 15000, 'order.status_changed(assigned) on the driver channel');
      await listenAdmin();
      return `driver socket got order.status_changed(assigned) after ${ev.latency} ms`;
    });

    // ------------------------------------------------- 12-15 driver, GPS, realtime, map
    await report.step('12', 'Driver API sees the assigned order', 'http', async () => {
      const active = (await S.d.api.ok('GET', '/api/v1/driver/orders/active')).orders;
      const mine = active.find((o) => o.id === OID);
      assert(mine, 'order not in the driver active list');
      S.cashDue = mine.payment.cash_to_collect;
      return `cash to collect ${S.cashDue} ${mine.payment.currency?.code ?? mine.payment.currency ?? ''}`;
    });

    await report.step('13-14', 'Driver GPS update → driver.location reaches the customer (WS) and the admin map channel (browser)', 'http+ws+browser', async () => {
      S.lat = 33.51 + Math.round(Math.random() * 500) / 1e5; S.lng = 36.29 + Math.round(Math.random() * 500) / 1e5;
      await S.d.api.ok('POST', '/api/v1/driver/location', { lat: S.lat, lng: S.lng });
      const ev = await S.cws.waitFor((e) => e.event === 'driver.location' && e.channel === `private-order.${OID}` && Math.abs(Number(e.data.lat) - S.lat) < 1e-6, 10000, 'driver.location on the order channel');
      await page.waitForFunction((lat) => window.__e2e.some(([k, e]) => k === 'location' && Math.abs(Number(e.lat) - lat) < 1e-6), S.lat, { timeout: 10000 });
      const leaked = S.ows.events.filter((e) => e.event === 'driver.location');
      assert(leaked.length === 0, 'another customer received the location');
      return `customer got it after ${ev.latency} ms; admin map channel got it; the other customer's socket received nothing (isolation)`;
    });

    await report.step('15', 'Filament driver map shows the new position', 'browser', async () => {
      await page.goto(BASE + '/admin/driver-map');
      await page.waitForFunction(() => window.waffrliDriverMap, null, { timeout: 20000 });
      await page.waitForFunction(([lat, lng]) => window.waffrliDriverMap.layer.getLayers().some((m) => Math.abs(m.getLatLng().lat - lat) < 1e-6 && Math.abs(m.getLatLng().lng - lng) < 1e-6), [S.lat, S.lng], { timeout: 15000 });
      await page.screenshot({ path: path.join(shots, '15-driver-map.png') });
      return `marker at ${S.lat}, ${S.lng}`;
    });

    await report.step('WS.3', 'Customer socket reconnects and keeps receiving events', 'ws', async () => {
      S.cws.close();
      S.cws = customerWs(S.c.token, 'customer-reconnected'); await S.cws.connect();
      const r = await S.cws.subscribe(`private-order.${OID}`);
      assert(r.status === 200, 'resubscribe');
      S.lat2 = S.lat + 0.0002;
      await S.d.api.ok('POST', '/api/v1/driver/location', { lat: S.lat2, lng: S.lng });
      const ev = await S.cws.waitFor((e) => e.event === 'driver.location' && Math.abs(Number(e.data.lat) - S.lat2) < 1e-6, 10000, 'location after reconnect');
      return `new socket ${S.cws.socketId}; event after ${ev.latency} ms`;
    });

    await report.step('FCM.3', 'Unregistered device token is deactivated by the push job (fake FCM "UNREGISTERED")', 'fake-provider', async () => {
      const dead = (await Cu().ok('POST', '/api/v1/customer/device-tokens', { token: 'fake-unregistered-' + uuid(), platform: 'android', device_id: 'e2e-device', device_name: 'E2E' })).device_token;
      S.deadId = dead.id;
      return `token #${dead.id} registered (replaces the previous one on the same device); checked after the next notification`;
    });


    await report.step('WS.4', 'Native client (no Origin header) of the customer receives the order events', 'ws', async () => {
      S.nws = customerWs(S.c.token, 'customer-native'); S.nws.origin = null; await S.nws.connect();
      const r = await S.nws.subscribe(`private-order.${OID}`);
      assert(r.status === 200, `auth → ${r.status}`);
      return `socket ${S.nws.socketId} without Origin subscribed to private-order.${OID} with the app's signed authorization`;
    });
    // ------------------------------------------------- 16-17 pickup and delivery
    await report.step('16', 'Driver picks up → customer receives picked_up', 'http+ws', async () => {
      await S.d.api.ok('POST', `/api/v1/driver/orders/${OID}/pickup`);
      const ev = await S.cws.waitFor(statusEvent('picked_up', OID), 10000, 'picked_up');
      if (S.nws) await S.nws.waitFor(statusEvent('picked_up', OID), 10000, 'picked_up on the native (no Origin) client');
      return `after ${ev.latency} ms (browser-style and native clients)`;
    });

    await report.step('17', 'Driver delivers and collects the cash remainder', 'http+ws', async () => {
      const wrong = await S.d.api.post(`/api/v1/driver/orders/${OID}/deliver`, { cash_collected: '0.01' });
      if (Number(S.cashDue) !== 0.01) assert(wrong.status === 422, `wrong cash amount → ${wrong.status}`);
      const o = (await S.d.api.ok('POST', `/api/v1/driver/orders/${OID}/deliver`, { cash_collected: S.cashDue })).order;
      const ev = await S.cws.waitFor(statusEvent('delivered', OID), 10000, 'delivered');
      const dup = await S.d.api.post(`/api/v1/driver/orders/${OID}/deliver`, { cash_collected: S.cashDue });
      assert(dup.status === 409, `duplicate delivery → ${dup.status}`);
      return `collected ${o.payment.cash_collected}; wrong amount 422; event after ${ev.latency} ms; duplicate 409`;
    });

    await report.step('FCM.3b', 'The unregistered token is now inactive', 'fake-provider', async () => {
      const t0 = Date.now();
      while (Date.now() - t0 < 15000) {
        const t = (await Cu().ok('GET', '/api/v1/customer/device-tokens')).device_tokens.find((x) => x.id === S.deadId);
        if (t && t.is_active === false) return `token #${S.deadId} deactivated by SendPushMessage`;
        await sleep(700);
      }
      throw new Error('token still active (queue worker running?)');
    });

    // ------------------------------------------------- 18 history, ledger, payments, settlement
    await report.step('18', 'History, payments, wallet ledger and driver cash records', 'http', async () => {
      const o = (await Cu().ok('GET', `/api/v1/customer/orders/${OID}`)).order;
      const path_ = o.status_history.map((h) => h.to);
      for (const s of ['pending', 'accepted', 'assigned', 'picked_up', 'delivered']) assert(path_.includes(s), `history lacks ${s}: ${path_}`);
      const cash = o.payments.find((p) => p.method === 'cash');
      if (cash) assert(cash.collected === cash.amount, `cash collected ${cash.collected} ≠ ${cash.amount}`);
      const wallet = o.payments.find((p) => p.method === 'wallet');
      let ledger = 'no wallet part';
      if (wallet) {
        const tx = (await Cu().ok('GET', '/api/v1/customer/wallet/transactions')).transactions;
        assert(tx.some((t) => t.type === 'order_payment' && (t.order_id === OID || t.order?.id === OID || JSON.stringify(t).includes(S.order.order_number))), 'no order_payment ledger entry for the order');
        ledger = `wallet ledger has the order_payment entry`;
      }
      const prev = (await Cu().ok('GET', '/api/v1/customer/orders?scope=previous&per_page=50')).orders;
      assert(prev.some((x) => x.id === OID), 'not in previous orders');
      const coll = await S.d.api.ok('GET', '/api/v1/driver/cash/collections');
      assert(JSON.stringify(coll).includes(S.order.order_number), 'not in driver cash collections');
      const summary = await S.d.api.ok('GET', '/api/v1/driver/cash/summary');
      await S.d.api.ok('GET', '/api/v1/driver/cash/settlements');
      return `history ${path_.join('→')}; ${o.payments.map((p) => `${p.method} ${p.amount}/${p.collected ?? '-'}`).join(', ')}; ${ledger}; driver cash collection listed (unsettled: ${JSON.stringify(summary).slice(0, 80)}…)`;
    });


    await report.step('E7', 'Admin refunds part of the delivered order in Filament → customer wallet credited', 'browser+http', async () => {
      const walletBefore = Number((await Cu().ok('GET', '/api/v1/customer/wallet')).wallets.find((w) => w.currency === S.currency.code)?.balance ?? 0);
      await page.goto(`${BASE}/admin/orders/${OID}`, { waitUntil: 'networkidle' });
      await page.waitForFunction(() => window.Livewire, null, { timeout: 15000 });
      const item = page.locator(`[wire\\:click*="mountAction('refund'"]`).first();
      if (!(await item.isVisible())) await page.locator('.fi-header .fi-dropdown-trigger, .fi-header-actions .fi-dropdown-trigger').last().click();
      await item.click();
      const select = page.locator('select[wire\\:model*="order_payment_id"]');
      await select.waitFor({ state: 'visible', timeout: 15000 });
      const modal = page.locator('.fi-modal', { has: select });
      const firstPayment = await select.locator('option').evaluateAll((o) => o.map((x) => x.value).find(Boolean));
      await select.selectOption(firstPayment);
      await modal.locator('input[wire\\:model*="amount"]').fill('1.00');
      await modal.locator('input[wire\\:model*="reason_code"]').fill('e2e_goodwill');
      await modal.locator('textarea[wire\\:model*="note"]').fill('E2E partial refund');
      await modal.locator('button[type=submit]').last().click();
      let after = walletBefore;
      for (let i = 0; i < 30 && after - walletBefore < 0.999; i++) { await sleep(500); after = Number((await Cu().ok('GET', '/api/v1/customer/wallet')).wallets.find((w) => w.currency === S.currency.code)?.balance ?? 0); }
      assert(Math.abs(after - walletBefore - 1) < 1e-9, `wallet ${walletBefore} → ${after}, expected +1.00`);
      const refunds = await Cu().ok('GET', '/api/v1/customer/refunds');
      assert(JSON.stringify(refunds).includes(S.order.order_number), 'refund not listed for the customer');
      await page.screenshot({ path: path.join(shots, 'E7-refund.png') });
      return `refund 1.00 recorded; customer ${S.currency.code} wallet ${walletBefore} → ${after}; listed in /customer/refunds`;
    });
    // ------------------------------------------------- 19 notifications AR/EN
    await report.step('19', 'Notifications in English and Arabic', 'http', async () => {
      const en = (await Cu().with({ locale: 'en' }).ok('GET', '/api/v1/customer/notifications')).notifications.filter((n) => n.references?.order_id === OID);
      const ar = (await Cu().with({ locale: 'ar' }).ok('GET', '/api/v1/customer/notifications')).notifications.filter((n) => en.some((e) => e.id === n.id));
      assert(en.length >= 3, `only ${en.length} notifications for the order`);
      assert(ar.length === en.length && ar.every((n) => /\p{Script=Arabic}/u.test(n.title)), 'Arabic titles');
      assert(en.every((n) => !/\p{Script=Arabic}/u.test(n.title)), 'English titles');
      return `${en.length} order notifications; e.g. "${en[0].title}" / "${ar[0].title}"`;
    });

    // ------------------------------------------------- E4 rating, E3 PLUS request, E5 currency
    await report.step('E4', 'Customer rates a delivered product', 'http', async () => {
      const r = await Cu().post(`/api/v1/customer/products/${S.order1.product.id}/rating`, { order_id: OID, rating: 5, comment: 'E2E' });
      assert([200, 409].includes(r.status), `rating → ${r.status} ${r.body?.error}`);
      return r.status === 200 ? 'rated 5' : 'already rated (409)';
    });
    await report.step('E3', 'PLUS custom request', 'http', async () => {
      const r = await Cu().post('/api/v1/customer/plus/requests', { type: 'custom', note: 'E2E question' });
      assert([200, 409].includes(r.status), `→ ${r.status}`);
      return r.status === 200 ? 'submitted' : 'one already pending (409)';
    });
    await report.step('E5', 'Currency presentation: display currency converts prices for display only', 'http', async () => {
      const cur = (await Cu().ok('GET', '/api/v1/customer/currencies')).currencies;
      const other = cur.find((c) => !c.is_default);
      if (!other) return { skip: 'only one currency' };
      const r = await Cu().patch('/api/v1/customer/me', { display_currency_id: other.id });
      if (r.status !== 200) return { skip: `display currency switch refused (${r.status}: ${r.body?.error}); WAFFRLI_CURRENCY_SWITCH_ENABLED=false` };
      const p = (await Cu().ok('GET', `/api/v1/customer/branches/${S.order1.branch.id}/products/${S.order1.product.id}`)).product;
      await Cu().ok('PATCH', '/api/v1/customer/me', { display_currency_id: null });
      assert(p.price.display && p.price.display.currency === other.code, 'display price');
      return `${p.price.amount} ${p.price.currency} shown as ${p.price.display.amount} ${p.price.display.currency}`;
    });

    // ------------------------------------------------- E6 suspension
    await report.step('E6', 'Suspension: an admin deactivates a customer → its token is refused; re-activation restores access', 'http', async () => {
      const login = await anon.ok('POST', '/api/v1/admin/login', { email: cfg.E2E_ADMIN_EMAIL, password: cfg.E2E_ADMIN_PASSWORD });
      const admin = new Api(BASE, { token: login.token });
      await admin.ok('POST', '/api/v1/admin/users/deactivate', { id: S.other.id });
      const refused = await S.other.api.get('/api/v1/customer/me');
      await admin.ok('POST', '/api/v1/admin/users/activate', { id: S.other.id });
      const back = await S.other.api.get('/api/v1/customer/me');
      assert(refused.status === 403, `suspended → ${refused.status}`);
      await admin.post('/api/v1/admin/logout');
      return `suspended → 403; re-activated → ${back.status}`;
    });

    // ------------------------------------------------- 20 unauthorized access
    await report.step('20', 'Unauthorized access is denied across apps and accounts', 'http', async () => {
      const checks = {
        'no token → customer/me': (await anon.get('/api/v1/customer/me')).status,
        'customer token → driver/me': (await Cu().get('/api/v1/driver/me')).status,
        'driver token → customer/me': (await S.d.api.get('/api/v1/customer/me')).status,
        'customer token → admin/cities/all': (await Cu().get('/api/v1/admin/cities/all')).status,
        "other customer → this order": (await S.other.api.get(`/api/v1/customer/orders/${OID}`)).status,
        'customer → admin panel route without session': (await fetch(BASE + '/admin/orders', { redirect: 'manual' })).status,
      };
      const bad = Object.entries(checks).filter(([, s]) => ![401, 403, 404, 302].includes(s));
      assert(bad.length === 0, `allowed: ${JSON.stringify(bad)}`);
      return Object.entries(checks).map(([k, v]) => `${k} ${v}`).join('; ');
    });
    await report.step('1.1', 'Single session: an older customer token is revoked by a new login', 'http', async () => {
      const oldApi = S.c.api;
      const wait = 62000 - (Date.now() - S.loginAt); // per-phone 60 s OTP cooldown
      if (wait > 0) await sleep(wait);
      const second = await customerLogin(cfg.E2E_CUSTOMER_PHONE, 'e2e-second-' + Date.now()).catch((e) => ({ error: e.message }));
      if (second.error) return { skip: `second OTP not available now: ${second.error}` };
      const old = await oldApi.get('/api/v1/customer/me');
      assert(old.status === 401, `old token → ${old.status}, expected 401`);
      S.c = second; return 'previous token → 401 after the new login';
    });
  } finally {
    await page.screenshot({ path: path.join(shots, 'last.png') }).catch(() => {});
    await browser.close();
    [S.cws, S.dws, S.ows, S.nws].forEach((w) => w?.close());
  }
}

main()
  .catch((e) => { console.error('Runner error:', e); report.steps.push({ id: '!', name: 'runner', kind: '-', status: 'FAIL', ms: 0, detail: String(e) }); })
  .finally(() => {
    const out = path.join(ROOT, 'e2e', 'results'); fs.mkdirSync(out, { recursive: true });
    fs.writeFileSync(path.join(out, 'latest.json'), JSON.stringify({ started: report.started, base: BASE, steps: report.steps }, null, 1));
    fs.writeFileSync(path.join(out, 'latest.md'), report.markdown({ base: BASE, reverb: WS.replace(/\/app\/[^?]+.*/, '') }));
    const c = report.counts();
    console.log(`\n${c.PASS} passed, ${c.FAIL} failed, ${c.SKIP} skipped → e2e/results/latest.md`);
    process.exit(c.FAIL ? 1 : 0);
  });
