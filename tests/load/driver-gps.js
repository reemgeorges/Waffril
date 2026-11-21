// WAFFRLI — driver workload (k6): GPS pings during a delivery, offer polling,
// active-order screen. One virtual user = one driver phone.
//
//   TARGET_ENV=staging BASE_URL=... DRIVERS=40 HOLD_MIN=10 k6 run tests/load/driver-gps.js
//
// GPS: one ping every GPS_INTERVAL seconds (default 5 s; the API allows 12/min
// per driver). A driver without an in-progress delivery gets 409 (expected):
// LoadTestDatasetSeeder gives each load-test driver one in-progress order so
// pings take the full path (position update + live broadcast).
import http from 'k6/http';
import { sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { assertSafeTarget, BASE_URL, driverFor, headers, ok } from './lib/common.js';

const DRIVERS = parseInt(__ENV.DRIVERS || '40', 10);
const HOLD_MIN = parseInt(__ENV.HOLD_MIN || '5', 10);
const GPS_INTERVAL = parseFloat(__ENV.GPS_INTERVAL || '5');
const pingsWithoutDelivery = new Counter('waffrli_gps_no_active_delivery');
const pingsThrottled = new Counter('waffrli_gps_throttled');

// PROPOSED thresholds (docs/performance/benchmark-plan.md).
export const options = {
  scenarios: {
    drivers: { executor: 'constant-vus', vus: DRIVERS, duration: `${HOLD_MIN}m` },
  },
  thresholds: {
    'http_req_duration{name:gps}': ['p(95)<300', 'p(99)<800'],
    'http_req_failed{name:gps}': ['rate<0.01'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  assertSafeTarget();
}

export default function () {
  const driver = driverFor(__VU);
  const t = driver.token;
  // Start positions spread around the demo city centre (Hama), moving slowly.
  const lat0 = 35.1343 + ((__VU % 20) - 10) * 0.002;
  const lng0 = 36.7496 + ((__VU % 13) - 6) * 0.002;

  for (let i = 0; i < 12; i++) {
    const lat = lat0 + i * 0.0002;
    const lng = lng0 + i * 0.0001;
    const res = http.post(`${BASE_URL}/api/v1/driver/location`, JSON.stringify({ lat, lng }), headers(t, { tags: { name: 'gps' } }));
    if (res.status === 409) pingsWithoutDelivery.add(1);
    if (res.status === 429) pingsThrottled.add(1);
    ok(res, 'gps', [409]);

    if (i % 2 === 0) {
      ok(http.get(`${BASE_URL}/api/v1/driver/offers/current`, headers(t, { tags: { name: 'offer-poll' } })), 'offer-poll', [404]);
    }
    if (i % 6 === 0) {
      ok(http.get(`${BASE_URL}/api/v1/driver/orders/active`, headers(t, { tags: { name: 'active-orders' } })), 'active-orders');
    }
    sleep(GPS_INTERVAL);
  }
}
