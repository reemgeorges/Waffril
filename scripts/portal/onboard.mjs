#!/usr/bin/env node
// One-time Hoppscotch onboarding for the WAFFRLI shared API portal (idempotent).
//   node scripts/portal/onboard.mjs
// Configures e-mail (magic link) sign-in through the SMTP server in .env
// (Mailpit locally). Hoppscotch keeps this in its own database and ignores the
// container environment until onboarding is done; the endpoint is open until
// then, so run this right after the first `docker compose --profile portal up -d`.
// Never prints secrets. The recovery token Hoppscotch returns is not stored.
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..', '..');
const env = Object.fromEntries(fs.readFileSync(path.join(root, '.env'), 'utf8').split(/\r?\n/).filter((l) => /^[A-Z0-9_]+=/.test(l)).map((l) => [l.split('=')[0], l.slice(l.indexOf('=') + 1).trim()]));
const base = (process.env.HOPPSCOTCH_URL || env.HOPPSCOTCH_URL || 'http://localhost:3000').replace(/\/$/, '');
const host = env.HOPPSCOTCH_SMTP_HOST || 'mailpit';
const port = env.HOPPSCOTCH_SMTP_PORT || '1025';
const user = env.HOPPSCOTCH_SMTP_USER || '';
const pass = env.HOPPSCOTCH_SMTP_PASSWORD || '';
const secure = (env.HOPPSCOTCH_SMTP_SECURE || 'false') === 'true';
const auth = user ? `${encodeURIComponent(user)}:${encodeURIComponent(pass)}@` : '';

for (let i = 0; i < 60; i++) {
  try { if ((await fetch(`${base}/backend/ping`)).ok) break; } catch { /* starting */ }
  await new Promise((r) => setTimeout(r, 2000));
}
const status = await (await fetch(`${base}/backend/v1/onboarding/status`)).json();
if (status.onboardingCompleted) {
  console.log(`Portal already onboarded (${base}). Nothing to do.`);
  process.exit(0);
}
const res = await fetch(`${base}/backend/v1/onboarding/config`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    VITE_ALLOWED_AUTH_PROVIDERS: 'EMAIL',
    MAILER_SMTP_ENABLE: 'true',
    MAILER_USE_CUSTOM_CONFIGS: 'false',
    MAILER_SMTP_URL: `${secure ? 'smtps' : 'smtp'}://${auth}${host}:${port}`,
    MAILER_ADDRESS_FROM: env.HOPPSCOTCH_MAIL_FROM || 'portal@waffrli.local',
  }),
});
const body = await res.json().catch(() => ({}));
if (!res.ok) {
  console.error(`Onboarding failed: HTTP ${res.status} ${JSON.stringify(body).slice(0, 300)}`);
  process.exit(1);
}
console.log(`Portal onboarded: e-mail sign-in via ${host}:${port}. The first user who signs in to ${base}/admin becomes the portal administrator.`);
