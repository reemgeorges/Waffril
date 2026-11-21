// WAFFRLI — mixed customer workload (k6).
//
// Each virtual user is ONE customer using the app with think time between
// screens (not a request flood). Run against an ISOLATED staging/local stack
// seeded with LoadTestDatasetSeeder:
//
//   TARGET_ENV=staging BASE_URL=https://staging.example PROFILE=250 HOLD_MIN=10 \
//     k6 run tests/load/customer-mixed.js
//
// CHECKOUT_RATE (0..1, default 0.05): share of journeys that end in a real
// checkout on the staging database (cash, no wallet). Set 0 for read-only.
import http from 'k6/http';
import { group, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { assertSafeTarget, BASE_URL, branches, customerFor, headers, ok, stagesFor, think, uuid } from './lib/common.js';

const PROFILE = __ENV.PROFILE || '50';
const HOLD_MIN = parseInt(__ENV.HOLD_MIN || '5', 10);
const CHECKOUT_RATE = parseFloat(__ENV.CHECKOUT_RATE || '0.05');
const THINK_MIN = parseFloat(__ENV.THINK_MIN || '3');
const THINK_MAX = parseFloat(__ENV.THINK_MAX || '8');

const checkoutLatency = new Trend('waffrli_checkout_ms', true);
const ordersPlaced = new Counter('waffrli_orders_placed');
const checkoutRejected = new Counter('waffrli_checkout_rejected');

// PROPOSED thresholds (not approved business requirements): see docs/performance/benchmark-plan.md.
export const options = {
  scenarios: {
    customers: { executor: 'ramping-vus', startVUs: 0, stages: stagesFor(PROFILE, HOLD_MIN), gracefulRampDown: '30s' },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{kind:read}': ['p(95)<500', 'p(99)<1500'],
    'http_req_duration{kind:search}': ['p(95)<800'],
    waffrli_checkout_ms: ['p(95)<2000'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  assertSafeTarget();
  const res = http.get(`${BASE_URL}/up`);
  if (res.status !== 200) {
    throw new Error(`${BASE_URL}/up answered ${res.status}`);
  }
}

const read = (token, path, name) => {
  const res = http.get(`${BASE_URL}/api/v1/customer/${path}`, headers(token, { tags: { name, kind: 'read' } }));
  ok(res, name);
  return res;
};

export default function () {
  const customer = customerFor(__VU);
  const t = customer.token;
  const branch = branches[(__VU + __ITER) % branches.length];

  group('home', () => {
    read(t, 'sliders', 'sliders');
    read(t, 'store-categories', 'store-categories');
    read(t, 'branches?per_page=20', 'branches');
    read(t, 'notifications/unread-count', 'unread-count');
  });
  sleep(think(THINK_MIN, THINK_MAX));

  group('browse store', () => {
    read(t, `branches/${branch.id}`, 'branch');
    read(t, `branches/${branch.id}/products?per_page=20`, 'branch-products');
  });
  sleep(think(THINK_MIN, THINK_MAX));

  group('product', () => {
    const productId = branch.product_ids[(__ITER + __VU) % branch.product_ids.length];
    read(t, `branches/${branch.id}/products/${productId}`, 'product');
  });
  sleep(think(THINK_MIN, THINK_MAX));

  if (__ITER % 3 === 0) {
    group('search', () => {
      const terms = ['shawarma', 'pizza', 'شاورما', 'برغر', 'coffee', 'chicken'];
      const res = http.get(`${BASE_URL}/api/v1/customer/search?q=${encodeURIComponent(terms[__ITER % terms.length])}`,
        headers(t, { tags: { name: 'search', kind: 'search' } }));
      ok(res, 'search', [422]);
    });
    sleep(think(THINK_MIN, THINK_MAX));
  }

  group('account', () => {
    read(t, 'orders?per_page=10', 'orders');
    read(t, 'notifications?per_page=20', 'notifications');
  });

  if (Math.random() < CHECKOUT_RATE) {
    group('cart and checkout', () => {
      const add = http.post(`${BASE_URL}/api/v1/customer/cart/items`,
        JSON.stringify({ branch_id: branch.id, product_id: branch.cheapest_product_id, quantity: branch.min_quantity }),
        headers(t, { tags: { name: 'cart-add', kind: 'write' } }));
      ok(add, 'cart-add', [409, 422]);
      const cartId = add.status < 300 ? add.json('data.cart.id') : null;
      sleep(think(1, 3));
      if (!cartId) {
        return;
      }
      http.get(`${BASE_URL}/api/v1/customer/carts/${cartId}`, headers(t, { tags: { name: 'cart-show', kind: 'read' } }));
      const key = uuid();
      const body = JSON.stringify({ address_id: customer.address_id, use_free_delivery: false });
      const res = http.post(`${BASE_URL}/api/v1/customer/carts/${cartId}/checkout`, body,
        headers(t, { headers: { 'Idempotency-Key': key }, tags: { name: 'checkout', kind: 'write' } }));
      checkoutLatency.add(res.timings.duration);
      // 409/422 are business refusals (closed branch, paused ordering, rate limit 429) — counted, not errors of the platform.
      if (res.status >= 200 && res.status < 300) {
        ordersPlaced.add(1);
        // The app retries with the same key: must return the same order, never a second one.
        const replay = http.post(`${BASE_URL}/api/v1/customer/carts/${cartId}/checkout`, body,
          headers(t, { headers: { 'Idempotency-Key': key }, tags: { name: 'checkout-replay', kind: 'write' } }));
        ok(replay, 'checkout-replay', [404]);
      } else {
        checkoutRejected.add(1, { status: String(res.status) });
        ok(res, 'checkout', [409, 422, 429]);
      }
    });
  }

  sleep(think(THINK_MIN * 2, THINK_MAX * 2));
}
