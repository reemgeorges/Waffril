// WAFFRLI — persistent WebSocket connections to Laravel Reverb (k6).
//
// Each virtual user = ONE customer app kept open: it connects with the Pusher
// protocol, authorises its private channel through the real API
// (/api/v1/customer/broadcasting/auth) and stays connected for HOLD_MIN,
// answering pings like the mobile SDK. Ramp: CONNECTIONS over RAMP_MIN.
//
//   TARGET_ENV=staging BASE_URL=https://staging.example WS_URL=wss://ws.staging.example \
//   REVERB_KEY=... CONNECTIONS=2000 RAMP_MIN=5 HOLD_MIN=10 k6 run tests/load/reverb-connections.js
//
// Note: one load generator usually needs `ulimit -n` above CONNECTIONS + 1,000.
import ws from 'k6/ws';
import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { assertSafeTarget, BASE_URL, customerFor, headers } from './lib/common.js';

const WS_URL = (__ENV.WS_URL || 'ws://127.0.0.1:8095').replace(/\/$/, '');
const KEY = __ENV.REVERB_KEY || 'loadtest-key';
const CONNECTIONS = parseInt(__ENV.CONNECTIONS || '200', 10);
const RAMP_MIN = parseFloat(__ENV.RAMP_MIN || '2');
const HOLD_MIN = parseFloat(__ENV.HOLD_MIN || '5');
const SUBSCRIBE = (__ENV.SUBSCRIBE || 'yes') === 'yes';

const connectTime = new Trend('waffrli_ws_connect_ms', true);
const subscribeTime = new Trend('waffrli_ws_subscribe_ms', true);
const established = new Rate('waffrli_ws_established');
const subscribed = new Rate('waffrli_ws_subscribed');
const unexpectedClose = new Counter('waffrli_ws_unexpected_close');
const serverPings = new Counter('waffrli_ws_server_pings');

// PROPOSED thresholds (docs/performance/benchmark-plan.md).
export const options = {
  scenarios: {
    sockets: {
      executor: 'ramping-vus', startVUs: 0, gracefulRampDown: '30s',
      stages: [{ duration: `${RAMP_MIN}m`, target: CONNECTIONS }, { duration: `${HOLD_MIN}m`, target: CONNECTIONS }, { duration: '30s', target: 0 }],
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
  assertSafeTarget();
}

export default function () {
  const customer = customerFor(__VU);
  const url = `${WS_URL}/app/${KEY}?protocol=7&client=js&version=8.4.0&flash=false`;
  const start = Date.now();
  let closingOnPurpose = false;

  const res = ws.connect(url, {}, (socket) => {
    let subscribeStart = 0;
    socket.on('message', (raw) => {
      let msg;
      try { msg = JSON.parse(raw); } catch (e) { return; }
      if (msg.event === 'pusher:connection_established') {
        connectTime.add(Date.now() - start);
        established.add(true);
        if (!SUBSCRIBE) return;
        const socketId = JSON.parse(msg.data).socket_id;
        const channel = `private-user.${customer.id}`;
        subscribeStart = Date.now();
        const auth = http.post(`${BASE_URL}/api/v1/customer/broadcasting/auth`, JSON.stringify({ socket_id: socketId, channel_name: channel }),
          headers(customer.token, { tags: { name: 'broadcasting-auth' } }));
        if (auth.status !== 200) {
          subscribed.add(false);
          return;
        }
        socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: auth.json('auth'), channel } }));
      } else if (msg.event === 'pusher_internal:subscription_succeeded') {
        subscribeTime.add(Date.now() - subscribeStart);
        subscribed.add(true);
      } else if (msg.event === 'pusher:ping') {
        serverPings.add(1);
        socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      } else if (msg.event === 'pusher:error') {
        subscribed.add(false);
      }
    });
    // Client keep-alive like pusher-js (activity timeout 30 s).
    socket.setInterval(() => socket.send(JSON.stringify({ event: 'pusher:ping', data: {} })), 25000);
    socket.on('close', () => { if (!closingOnPurpose) unexpectedClose.add(1); });
    socket.on('error', () => { established.add(false); });
    // Stay connected until the end of the hold phase (minus the ramp already spent).
    socket.setTimeout(() => { closingOnPurpose = true; socket.close(); }, (RAMP_MIN + HOLD_MIN) * 60000);
  });
  check(res, { 'ws handshake 101': (r) => r && r.status === 101 });
}
