// WAFFRLI — persistent WebSocket connections to Laravel Reverb (k6).
//
// Each virtual user = ONE customer app kept open: it connects with the Pusher
// protocol, authorises its private channel through the real API
// (/api/v1/customer/broadcasting/auth) and stays connected until the END of
// the hold phase (test start + RAMP_MIN + HOLD_MIN), answering pings like the
// mobile SDK.
//
//   TARGET_ENV=staging ALLOWED_HOSTS=staging.example,ws.staging.example \
//   BASE_URL=https://staging.example WS_URL=wss://ws.staging.example \
//   REVERB_KEY=... CONNECTIONS=2000 RAMP_MIN=5 HOLD_MIN=10 k6 run tests/load/reverb-connections.js
//
// Accounting (every attempt is in the denominator):
//   waffrli_ws_established  — per attempt: true only for a 101 handshake AND pusher:connection_established
//   waffrli_ws_subscribed   — per established socket: true only for subscription_succeeded within SUBSCRIBE_TIMEOUT_S
//   waffrli_ws_unexpected_close — closes before the planned end of the hold phase
// A failed attempt waits RETRY_S before the VU tries again (no hot retry loop).
// One load generator usually needs `ulimit -n` above CONNECTIONS + 1,000.
import ws from 'k6/ws';
import http from 'k6/http';
import { sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { assertSafeTarget, BASE_URL, customerFor, headers, WS_URL } from './lib/common.js';

const KEY = __ENV.REVERB_KEY || 'loadtest-key';
const CONNECTIONS = parseInt(__ENV.CONNECTIONS || '200', 10);
const RAMP_MIN = parseFloat(__ENV.RAMP_MIN || '2');
const HOLD_MIN = parseFloat(__ENV.HOLD_MIN || '5');
const SUBSCRIBE = (__ENV.SUBSCRIBE || 'yes') === 'yes';
const SUBSCRIBE_TIMEOUT_S = parseFloat(__ENV.SUBSCRIBE_TIMEOUT_S || '10');
const RETRY_S = parseFloat(__ENV.RETRY_S || '5');

const connectTime = new Trend('waffrli_ws_connect_ms', true);
const subscribeTime = new Trend('waffrli_ws_subscribe_ms', true);
const established = new Rate('waffrli_ws_established');
const subscribed = new Rate('waffrli_ws_subscribed');
const attempts = new Counter('waffrli_ws_attempts');
const unexpectedClose = new Counter('waffrli_ws_unexpected_close');
const socketErrors = new Counter('waffrli_ws_errors');
const serverPings = new Counter('waffrli_ws_server_pings');

// PROPOSED thresholds (docs/performance/benchmark-plan.md).
export const options = {
  scenarios: {
    sockets: {
      executor: 'ramping-vus', startVUs: 0, gracefulRampDown: '30s', gracefulStop: '30s',
      stages: [{ duration: `${RAMP_MIN}m`, target: CONNECTIONS }, { duration: `${HOLD_MIN}m`, target: CONNECTIONS }, { duration: '10s', target: 0 }],
    },
  },
  thresholds: {
    waffrli_ws_established: ['rate>0.99'],
    waffrli_ws_subscribed: ['rate>0.99'],
    waffrli_ws_connect_ms: ['p(95)<1000'],
    waffrli_ws_unexpected_close: ['count<10'],
  },
};

export function setup() {
  assertSafeTarget({ websocket: true });
  return { endAt: Date.now() + (RAMP_MIN + HOLD_MIN) * 60000 };
}

export default function (data) {
  const remaining = data.endAt - Date.now();
  if (remaining <= 1000) {
    sleep(1);   // hold phase over: do not open new sockets during ramp-down
    return;
  }
  const customer = customerFor(__VU);
  const url = `${WS_URL}/app/${KEY}?protocol=7&client=js&version=8.4.0&flash=false`;
  const start = Date.now();
  let isEstablished = false;
  let isSubscribed = false;
  let plannedClose = false;
  attempts.add(1);

  let res;
  try {
    res = ws.connect(url, {}, (socket) => {
      let subscribeStart = 0;
      socket.on('message', (raw) => {
        let msg;
        try { msg = JSON.parse(raw); } catch (e) { return; }
        if (msg.event === 'pusher:connection_established' && !isEstablished) {
          isEstablished = true;
          connectTime.add(Date.now() - start);
          if (!SUBSCRIBE) return;
          const socketId = JSON.parse(msg.data).socket_id;
          const channel = `private-user.${customer.id}`;
          subscribeStart = Date.now();
          const auth = http.post(`${BASE_URL}/api/v1/customer/broadcasting/auth`, JSON.stringify({ socket_id: socketId, channel_name: channel }),
            headers(customer.token, { tags: { name: 'broadcasting-auth' } }));
          if (auth.status !== 200) return;   // counted as not subscribed by the timeout below
          socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: auth.json('auth'), channel } }));
        } else if (msg.event === 'pusher_internal:subscription_succeeded' && !isSubscribed) {
          isSubscribed = true;
          subscribeTime.add(Date.now() - subscribeStart);
        } else if (msg.event === 'pusher:ping') {
          serverPings.add(1);
          socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
        }
      });
      // Exactly one verdict per established socket: subscribed in time, or not.
      if (SUBSCRIBE) {
        socket.setTimeout(() => { if (isEstablished) subscribed.add(isSubscribed); }, SUBSCRIBE_TIMEOUT_S * 1000);
      }
      // Handshake upgraded but no connection_established in time: a failed attempt.
      socket.setTimeout(() => { if (!isEstablished) socket.close(); }, SUBSCRIBE_TIMEOUT_S * 1000);
      socket.setInterval(() => socket.send(JSON.stringify({ event: 'pusher:ping', data: {} })), 25000);
      socket.on('error', () => socketErrors.add(1));
      socket.on('close', () => {
        if (isEstablished && !plannedClose) unexpectedClose.add(1);
        // Closed before the subscribe verdict: count it as not subscribed.
        if (SUBSCRIBE && isEstablished && !isSubscribed && Date.now() - start < SUBSCRIBE_TIMEOUT_S * 1000) subscribed.add(false);
      });
      // Stay connected until the end of the hold phase of THIS test run.
      socket.setTimeout(() => { plannedClose = true; socket.close(); }, Math.max(1000, remaining));
    });
  } catch (e) {
    res = null;   // DNS / refused / TLS: a failed attempt, never omitted
  }

  established.add(isEstablished && !!res && res.status === 101);
  if (!isEstablished) {
    sleep(RETRY_S);   // back off; no hot loop of failing handshakes
  }
}
