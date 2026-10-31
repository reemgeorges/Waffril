// WAFFRLI E2E helpers (Node 22+, no dependencies besides Playwright for the
// browser steps). Talks to the RUNNING Docker stack: real HTTP, a real
// WebSocket connection to Reverb (Pusher protocol 7), a real Chromium.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

export const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');

/** KEY=VALUE pairs of a dotenv file (missing file → {}). Values are never printed. */
export function readEnv(file) {
  if (!fs.existsSync(file)) return {};
  const out = {};
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m) out[m[1]] = m[2].replace(/^"(.*)"$/, '$1');
  }
  return out;
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const uuid = () => crypto.randomUUID();

/** Results: every step says HOW it was verified (real HTTP, real WebSocket, browser, fake provider). */
export class Report {
  constructor() { this.steps = []; this.started = new Date(); }
  async step(id, name, kind, fn) {
    const t0 = Date.now();
    try {
      const detail = await fn();
      if (detail && detail.skip) {
        this.steps.push({ id, name, kind, status: 'SKIP', ms: Date.now() - t0, detail: detail.skip });
        console.log(`  SKIP ${id} ${name} — ${detail.skip}`);
      } else {
        this.steps.push({ id, name, kind, status: 'PASS', ms: Date.now() - t0, detail: detail ?? '' });
        console.log(`  PASS ${id} ${name}${detail ? ' — ' + String(detail).slice(0, 160) : ''}`);
      }
      return detail;
    } catch (e) {
      this.steps.push({ id, name, kind, status: 'FAIL', ms: Date.now() - t0, detail: String(e.message || e).slice(0, 600) });
      console.log(`  FAIL ${id} ${name} — ${String(e.message || e).slice(0, 300)}`);
      return undefined;
    }
  }
  counts() {
    return ['PASS', 'FAIL', 'SKIP'].reduce((a, s) => ({ ...a, [s]: this.steps.filter((x) => x.status === s).length }), {});
  }
  markdown(meta) {
    const c = this.counts();
    let md = `# WAFFRLI E2E run — ${this.started.toISOString()}\n\n`;
    md += `Target: ${meta.base} (Docker Compose stack, MySQL 8.4, Redis queue worker, Reverb ${meta.reverb}). Runner: \`node e2e/run.mjs\`.\n\n`;
    md += `**${c.PASS} passed, ${c.FAIL} failed, ${c.SKIP} skipped** (${this.steps.length} steps).\n\n`;
    md += 'Kinds: `http` real HTTP request to the API · `ws` real WebSocket to Reverb (Pusher protocol) · `browser` real Chromium on Filament · `fake-provider` SMS/push go to the local fake drivers (nothing is sent to a real SMS, WhatsApp or FCM service).\n\n';
    md += '| # | Step | Kind | Result | ms | Detail |\n|---|---|---|---|---|---|\n';
    for (const s of this.steps) md += `| ${s.id} | ${s.name} | ${s.kind} | ${s.status} | ${s.ms} | ${String(s.detail).replace(/\|/g, '\\|').replace(/\n/g, ' ').slice(0, 220)} |\n`;
    return md;
  }
}

export function assert(cond, message) { if (!cond) throw new Error(message); }

/** JSON API client with the WAFFRLI envelope. */
export class Api {
  constructor(base, { token = null, locale = 'en' } = {}) { Object.assign(this, { base, token, locale }); }
  with(opts) { return new Api(this.base, { token: this.token, locale: this.locale, ...opts }); }
  async call(method, url, body, headers = {}) {
    const h = { Accept: 'application/json', 'Accept-Language': this.locale, ...headers };
    if (this.token) h.Authorization = `Bearer ${this.token}`;
    let payload;
    if (body instanceof FormData) payload = body;
    else if (body !== undefined) { h['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    const res = await fetch(this.base + url, { method, headers: h, body: payload });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not JSON */ }
    return { status: res.status, body: json, text };
  }
  get(u, h) { return this.call('GET', u, undefined, h); }
  post(u, b = {}, h) { return this.call('POST', u, b, h); }
  patch(u, b = {}, h) { return this.call('PATCH', u, b, h); }
  delete(u, h) { return this.call('DELETE', u, undefined, h); }
  async ok(method, url, body, headers) {
    const r = await this.call(method, url, body, headers);
    assert(r.status === 200 && r.body?.status === true, `${method} ${url} → ${r.status} ${r.body?.error ?? r.text.slice(0, 200)}`);
    return r.body.data;
  }
}

/**
 * Minimal Pusher-protocol client for Reverb over the built-in WebSocket.
 * authorize(socketId, channel) must return {status, body} from the app's
 * broadcasting/auth endpoint (so channel authorization is the REAL one).
 */
export class ReverbClient {
  constructor(url, authorize, label, origin = 'http://localhost:8080') { Object.assign(this, { url, authorize, label, origin, events: [], subscribed: new Set() }); }
  connect(timeout = 8000) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`${this.label}: no connection_established within ${timeout} ms`)), timeout);
      // Node 22's WebSocket (undici) accepts headers: send an Origin like a browser does (null = none).
      this.ws = this.origin ? new WebSocket(this.url, { headers: { Origin: this.origin } }) : new WebSocket(this.url);
      this.ws.onerror = (e) => { clearTimeout(timer); reject(new Error(`${this.label}: websocket error ${e.message ?? ''}`)); };
      this.ws.onmessage = (msg) => {
        const m = JSON.parse(msg.data);
        const data = typeof m.data === 'string' ? (() => { try { return JSON.parse(m.data); } catch { return m.data; } })() : m.data;
        if (m.event === 'pusher:error' && !this.socketId) { clearTimeout(timer); reject(new Error(`${this.label}: pusher:error ${JSON.stringify(data)}`)); return; }
        if (m.event === 'pusher:connection_established') { clearTimeout(timer); this.socketId = data.socket_id; resolve(this.socketId); return; }
        if (m.event === 'pusher:ping') { this.ws.send(JSON.stringify({ event: 'pusher:pong', data: {} })); return; }
        if (m.event === 'pusher_internal:subscription_succeeded') { this.subscribed.add(m.channel); return; }
        this.events.push({ event: m.event, channel: m.channel, data, at: Date.now() });
      };
    });
  }
  /** Authorize with the app, then subscribe. Returns {status} of the auth call (200 = subscribed). */
  async subscribe(channel, timeout = 6000) {
    const auth = await this.authorize(this.socketId, channel);
    if (auth.status !== 200 || !auth.body?.auth) return { status: auth.status };
    this.ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: auth.body.auth, channel_data: auth.body.channel_data, channel } }));
    const t0 = Date.now();
    while (!this.subscribed.has(channel)) {
      if (Date.now() - t0 > timeout) throw new Error(`${this.label}: no subscription_succeeded for ${channel}`);
      await sleep(50);
    }
    return { status: 200 };
  }
  /** Subscribe with a FORGED signature (bypassing the app): Reverb itself must refuse it. */
  async subscribeForged(channel, timeout = 3000) {
    this.ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: 'forged:' + '0'.repeat(64), channel } }));
    const t0 = Date.now();
    while (Date.now() - t0 < timeout) {
      if (this.subscribed.has(channel)) return 'subscribed';
      if (this.events.some((e) => e.event === 'pusher:error' || (e.event === 'pusher:subscription_error' && e.channel === channel))) return 'refused';
      await sleep(50);
    }
    return 'no-answer';
  }
  async waitFor(pred, timeout = 10000, what = 'event') {
    const t0 = Date.now();
    while (Date.now() - t0 < timeout) {
      const hit = this.events.find(pred);
      if (hit) return { ...hit, latency: hit.at - t0 };
      await sleep(50);
    }
    throw new Error(`${this.label}: ${what} not received within ${timeout} ms (got: ${this.events.map((e) => e.channel + ':' + e.event).slice(-6).join(', ') || 'nothing'})`);
  }
  close() { try { this.ws.close(); } catch { /* already closed */ } }
}
