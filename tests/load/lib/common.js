// WAFFRLI load tests — shared helpers (k6). See tests/load/README.md.
//
// SAFETY: refuses to run unless TARGET_ENV is "staging" or "local" and the
// base URL is not on the production host list below. Never point these
// scripts at production without written authorization.
import { SharedArray } from 'k6/data';
import { check, fail } from 'k6';

export const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '');
const TARGET_ENV = __ENV.TARGET_ENV || '';
const FORBIDDEN_HOSTS = (__ENV.FORBIDDEN_HOSTS || '').split(',').map((h) => h.trim()).filter(Boolean);

export function assertSafeTarget() {
  if (!['staging', 'local'].includes(TARGET_ENV)) {
    fail('Set TARGET_ENV=staging (or local). These scripts never run against production.');
  }
  const host = BASE_URL.replace(/^https?:\/\//, '').split('/')[0];
  if (FORBIDDEN_HOSTS.some((h) => host === h || host.endsWith('.' + h))) {
    fail(`BASE_URL host ${host} is on FORBIDDEN_HOSTS.`);
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

export function headers(token, extra = {}) {
  return {
    headers: Object.assign({ Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'ar', 'Content-Type': 'application/json' }, extra.headers || {}),
    tags: extra.tags || {},
    timeout: extra.timeout || '30s',
  };
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
