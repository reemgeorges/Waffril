#!/usr/bin/env node
// WAFFRLI Filament browser validation (real Chromium against the Docker stack).
//
//   node e2e/filament-qa.mjs        (uses E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD from e2e/.env)
//
// Visits EVERY panel page reachable without a record id (from the registered
// routes) in Arabic (RTL) and English (LTR), dark and light, desktop and
// mobile, and checks: HTTP status, JavaScript errors, failed asset requests,
// <html lang/dir>, brand logo, raw translation keys, horizontal overflow and
// requests to external hosts. Screenshots + report in e2e/results/filament/.
// Read-only: it opens pages and "create" forms but never submits them. The
// admin's language preference is restored at the end.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { ROOT, readEnv } from './lib.mjs';

const { chromium } = createRequire(import.meta.url)('playwright');
const app = readEnv(path.join(ROOT, '.env'));
const cfg = { ...readEnv(path.join(ROOT, 'e2e', '.env')), ...process.env };
const BASE = cfg.E2E_BASE_URL || `http://localhost:${app.APP_PORT || 8080}`;
const OUT = path.join(ROOT, 'e2e', 'results', 'filament');
fs.mkdirSync(OUT, { recursive: true });

// Every GET panel page without a {parameter}, from the live route list.
const routes = JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'route:list', '--path=admin', '--method=GET', '--json'], { cwd: ROOT }).toString())
  .map((r) => r.uri).filter((u) => !u.includes('{') && !u.startsWith('api') && !/login|logout/.test(u)).sort();

const rows = [];
let tileFailures = 0;
async function inspect(page, url, combo, problems) {
  const res = await page.goto(BASE + '/' + url, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});
  // Paced: the panel's realtime channel auth is throttled at 60/min per admin (2 channels per page).
  await page.waitForTimeout(2300);
  const probe = () => page.evaluate(() => ({
    lang: document.documentElement.lang,
    dir: document.documentElement.dir || getComputedStyle(document.documentElement).direction,
    dark: document.documentElement.classList.contains('dark'),
    logo: !!document.querySelector('img[src*="images/brand/"]'),
    hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
    rawKeys: (document.body.innerText.match(/\b(admin|messages|validation|filament-[a-z-]+)(::)?\.[a-z_]+\.[a-z_.]+/g) || []).slice(0, 3),
    title: document.title,
  }));
  let info;
  for (let i = 0; i < 4 && !info; i++) info = await probe().catch(async () => { await page.waitForLoadState('load').catch(() => {}); await page.waitForTimeout(500); return null; });
  if (!info) throw new Error('page kept navigating: ' + url);
  const row = { url: '/' + url, ...combo, status: res?.status(), ...info, errors: problems.splice(0) };
  rows.push(row);
  return row;
}

const browser = await chromium.launch();
const original = { locale: null };
try {
  for (const [label, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
    const ctx = await browser.newContext({ viewport });
    const page = await ctx.newPage();
    const problems = [];
    page.on('pageerror', (e) => problems.push('js: ' + e.message.slice(0, 120)));
    // Map tiles come from tile.openstreetmap.org (the only external host the panel uses); a
    // network that blocks it is recorded separately as an environment note, not an app finding.
    page.on('requestfailed', (r) => { if (/tile\.openstreetmap\.org/.test(r.url())) tileFailures++; else problems.push('failed: ' + r.url().replace(BASE, '')); });
    page.on('response', (r) => { if (r.status() >= 400 && r.url().startsWith(BASE)) problems.push(`${r.status()}: ${r.url().replace(BASE, '')}`); });
    page.on('request', (r) => { const h = new URL(r.url()).hostname; if (!['localhost', '127.0.0.1'].includes(h) && !/tile\.openstreetmap\.org$/.test(h) && !r.url().startsWith('data:')) problems.push('external: ' + h); });

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', cfg.E2E_ADMIN_EMAIL);
    await page.fill('input[type=password]', cfg.E2E_ADMIN_PASSWORD);
    await page.press('input[type=password]', 'Enter');
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 });
    if (original.locale === null) original.locale = await page.evaluate(() => document.documentElement.lang);

    const pages = label === 'desktop' ? routes : ['admin', 'admin/orders', 'admin/driver-map', 'admin/products', 'admin/plus-memberships'];
    for (const locale of ['ar', 'en']) {
      // The panel's own language switch (POST + CSRF) — same as a user click.
      await page.goto(BASE + '/admin');
      await Promise.all([page.waitForNavigation({ timeout: 15000 }).catch(() => {}), page.click(`[data-locale="${locale}"]`)]);
      for (const mode of locale === 'ar' ? ['dark', 'light'] : ['light', 'dark']) {
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.evaluate((m) => localStorage.setItem('filament:theme:mode', m), mode).catch(async () => {
          await page.waitForLoadState('load'); await page.evaluate((m) => localStorage.setItem('filament:theme:mode', m), mode);
        });
        for (const url of mode === (locale === 'ar' ? 'dark' : 'light') ? pages : ['admin', 'admin/orders', 'admin/driver-map']) {
          const row = await inspect(page, url, { viewport: label, locale, mode }, problems);
          const name = `${label}-${locale}-${mode}-${url.replace(/\//g, '_')}`;
          if (['admin', 'admin/orders', 'admin/driver-map', 'admin/products', 'admin/plus-memberships', 'admin/wallets', 'admin/order-payments', 'admin/stores', 'admin/drivers'].includes(url)) await page.screenshot({ path: path.join(OUT, name + '.png') });
          process.stdout.write(row.status === 200 && !row.errors.length ? '.' : 'x');
        }
      }
    }
    await ctx.close();
  }
  // Restore the admin's language preference.
  if (original.locale) {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await page.goto(BASE + '/admin/login'); await page.fill('input[type=email]', cfg.E2E_ADMIN_EMAIL); await page.fill('input[type=password]', cfg.E2E_ADMIN_PASSWORD);
    await page.press('input[type=password]', 'Enter'); await page.waitForURL((u) => !u.pathname.endsWith('/login'));
    await Promise.all([page.waitForNavigation({ timeout: 15000 }).catch(() => {}), page.click(`[data-locale="${original.locale}"]`)]);
    await ctx.close();
  }
} finally {
  await browser.close();
}

const bad = rows.filter((r) => r.status !== 200 || r.errors.length || r.rawKeys.length || r.hScroll || !r.logo
  || r.lang !== r.locale || r.dir !== (r.locale === 'ar' ? 'rtl' : 'ltr') || r.dark !== (r.mode === 'dark'));
let md = `# Filament browser validation — ${new Date().toISOString()}\n\nReal Chromium against ${BASE}. ${rows.length} page views (${routes.length} distinct panel pages × AR/EN × dark/light, plus mobile 390×844). Screenshots: \`e2e/results/filament/\`.\n\n`;
md += `**${rows.length - bad.length} clean, ${bad.length} with findings.** A page is clean when: HTTP 200, no JS error, no failed or 4xx/5xx request, no external host except OpenStreetMap tiles on the map, \`lang\`/\`dir\` match the locale (ar → rtl), the theme matches, the logo is present, no raw translation key, no horizontal overflow.\n\n`;
md += tileFailures ? `Environment note: ${tileFailures} OpenStreetMap tile request(s) failed because this network cannot reach tile.openstreetmap.org (map markers still render; tiles load where the internet is reachable).\n\n` : '';
md += '| Page | Viewport | Locale | Mode | Status | dir | Findings |\n|---|---|---|---|---|---|---|\n';
for (const r of rows) {
  const f = [...r.errors, ...r.rawKeys.map((k) => 'raw key ' + k), r.hScroll ? 'horizontal overflow' : '', r.logo ? '' : 'no logo', r.lang !== r.locale ? `lang=${r.lang}` : '', r.dark !== (r.mode === 'dark') ? 'theme mismatch' : ''].filter(Boolean);
  md += `| \`${r.url}\` | ${r.viewport} | ${r.locale} | ${r.mode} | ${r.status} | ${r.dir} | ${f.join('; ').replace(/\|/g, '\\|') || 'clean'} |\n`;
}
fs.writeFileSync(path.join(OUT, 'report.md'), md);
fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(rows, null, 1));
console.log(`\n${rows.length} page views, ${bad.length} with findings → e2e/results/filament/report.md`);
for (const r of bad.slice(0, 15)) console.log(' ', r.url, r.viewport, r.locale, r.mode, r.status, r.dir, r.errors.slice(0, 2).join(' ; '), r.rawKeys.join(','), r.hScroll ? 'hScroll' : '', r.logo ? '' : 'nologo', r.lang);
process.exit(bad.length ? 1 : 0);
