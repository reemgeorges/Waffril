// WAFFRLI load tests — shared helpers (k6). See tests/load/README.md.
//
// SAFETY: refuses to run unless TARGET_ENV is "staging" or "local" and the
// base URL is not on the production host list below. Never point these
// scripts at production without written authorization.
import { SharedArray } from 'k6/data';
import { check, fail } from 'k6';
import http from 'k6/http';

export const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '');
export const WS_URL = (__ENV.WS_URL || 'ws://127.0.0.1:8095').replace(/\/$/, '');
const TARGET_ENV = __ENV.TARGET_ENV || '';
const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];
const list = (v) => (v || '').split(',').map((h) => h.trim().toLowerCase()).filter(Boolean);
// Explicit allowlist of the staging host names (host only, no port). Required:
// TARGET_ENV alone never authorises a target.
const ALLOWED_HOSTS = list(__ENV.ALLOWED_HOSTS);
const FORBIDDEN_HOSTS = list(__ENV.FORBIDDEN_HOSTS);

export function hostOf(url) {
  const m = String(url).match(/^[a-z]+:\/\/(\[[^\]]+\]|[^/:?#]+)/i);
  return m ? m[1].replace(/^\[|\]$/g, '').toLowerCase() : '';
}

function assertHost(label, url) {
  const host = hostOf(url);
  if (!host) fail(`${label} (${url}) is not a valid URL.`);
  if (FORBIDDEN_HOSTS.some((h) => host === h || host.endsWith('.' + h))) fail(`${label} host ${host} is on FORBIDDEN_HOSTS.`);
  const isLocal = LOCAL_HOSTS.includes(host);
  if (TARGET_ENV === 'local' && !isLocal) fail(`TARGET_ENV=local only allows ${LOCAL_HOSTS.join(', ')}; ${label} is ${host}.`);
  if (!isLocal && !ALLOWED_HOSTS.includes(host)) fail(`${label} host ${host} is not in ALLOWED_HOSTS (exact staging host names, comma-separated).`);
}

/**
 * Refuses to run unless: TARGET_ENV is staging or local, every target host
 * (API and, when used, WebSocket) is local or explicitly listed in
 * ALLOWED_HOSTS and not in FORBIDDEN_HOSTS, and — for tests that WRITE
 * (orders) — ALLOW_WRITES=yes is set.
 */
export function assertSafeTarget({ websocket = false, writes = false } = {}) {
  if (!['staging', 'local'].includes(TARGET_ENV)) {
    fail('Set TARGET_ENV=staging (or local). These scripts never run against production.');
  }
  assertHost('BASE_URL', BASE_URL);
  if (websocket) assertHost('WS_URL', WS_URL);
  if (writes && __ENV.ALLOW_WRITES !== 'yes') {
    fail('This run creates orders: set ALLOW_WRITES=yes (disposable staging data only), or CHECKOUT_RATE=0.');
  }
}

// Tokens written by LoadTestDatasetSeeder (storage/app/loadtest/tokens.json).
// Relative paths resolve from this file (tests/load/lib/).
const TOKENS_FILE = __ENV.TOKENS_FILE || '../../../storage/app/loadtest/tokens.json';
const data = JSON.parse(open(TOKENS_FILE));
export const customers = new SharedArray('customers', () => data.customers);
export const drivers = new SharedArray('drivers', () => data.drivers);
export const branches = new SharedArray('branches', () => data.branches);

/** Deterministic: VU n always uses the same customer (wraps around). */
export function customerFor(vu) {
  return customers[(vu - 1) % customers.length];
}

export function driverFor(vu) {
  return drivers[(vu - 1) % drivers.length];
}

/**
 * Request params. `expected`: extra statuses that are legitimate business
 * outcomes for THIS request (e.g. 409/422 refusals, 429 throttling); they are
 * then not counted in http_req_failed, which keeps measuring real failures
 * (5xx, timeouts). Each script still reports them in its own counters.
 */
export function headers(token, extra = {}) {
  const params = {
    headers: Object.assign({ Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'ar', 'Content-Type': 'application/json' }, extra.headers || {}),
    tags: extra.tags || {},
    timeout: extra.timeout || '30s',
  };
  if (extra.expected && extra.expected.length) {
    params.responseCallback = http.expectedStatuses({ min: 200, max: 299 }, ...extra.expected);
  }
  return params;
}

/** A response is "ok" for the load test when it is 2xx, or one of the expected business statuses. */
export function ok(res, name, expected = []) {
  return check(res, { [`${name}: status ok`]: (r) => (r.status >= 200 && r.status < 300) || expected.includes(r.status) });
}

/** Think time with jitter (seconds). */
export function think(min, max) {
  return min + Math.random() * (max - min);
}

export function uuid() {
  // RFC 4122 v4 shape; sufficient for idempotency keys in a test.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

/**
 * Load profiles: the number of ACTIVE virtual users (each one a person using
 * the app with think time), not requests per second. PROFILE=50|100|250|500|1000|2000.
 */
export function stagesFor(profile, holdMinutes) {
  const target = parseInt(profile, 10);
  const hold = `${holdMinutes}m`;
  const ramp = target <= 250 ? '1m' : target <= 1000 ? '3m' : '5m';
  return [
    { duration: ramp, target },
    { duration: hold, target },
    { duration: '1m', target: 0 },
  ];
}
