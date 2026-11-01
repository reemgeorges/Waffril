#!/usr/bin/env node
// Two developers collaborate in the shared API portal (Hoppscotch CE, compose
// profile "portal"): real Chromium, two separate browser profiles (cookie jars)
// and two separate accounts signed in by e-mail magic link (Mailpit locally).
//
//   docker compose --profile portal up -d && node scripts/portal/onboard.mjs
//   node e2e/portal-collab.mjs
//
// Steps: both developers sign in; developer A becomes portal admin, creates
// the "WAFFRLI" workspace (team), imports the generated collections
// (api-tests/hoppscotch/*.json — all Bruno requests, no retyping) and team
// environments, and invites developer B; B accepts the e-mailed invitation in
// the UI, sees the shared collections, edits a request in the UI and saves it;
// A sees B's change; B sends real requests to the WAFFRLI API from the portal
// and the converted Bruno assertions pass; B's secrets/tokens are not visible
// to A. Report: e2e/results/portal.md. Local only.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { Report, ROOT, assert, readEnv, sleep } from './lib.mjs';

const { chromium } = createRequire(import.meta.url)('playwright');
const cfg = { ...readEnv(path.join(ROOT, '.env')), ...readEnv(path.join(ROOT, 'e2e', '.env')), ...process.env };
const PORTAL = (cfg.E2E_PORTAL_URL || cfg.HOPPSCOTCH_URL || 'http://localhost:3000').replace(/\/$/, '');
const MAILPIT = (cfg.E2E_MAILPIT_URL || 'http://localhost:8025').replace(/\/$/, '');
const DEV_A = cfg.E2E_PORTAL_DEV_A || 'dev-a@waffrli.local';
const DEV_B = cfg.E2E_PORTAL_DEV_B || 'dev-b@waffrli.local';
const TEAM = cfg.E2E_PORTAL_TEAM || 'WAFFRLI';
const COLLECTIONS = JSON.parse(fs.readFileSync(path.join(ROOT, 'api-tests', 'hoppscotch', 'waffrli-collections.json'), 'utf8'));
const ENVIRONMENTS = JSON.parse(fs.readFileSync(path.join(ROOT, 'api-tests', 'hoppscotch', 'waffrli-environments.json'), 'utf8'));
const OUT = path.join(ROOT, 'e2e', 'results'); fs.mkdirSync(OUT, { recursive: true });
const report = new Report();

const gql = (page, query, variables = {}) => page.evaluate(async ([q, v]) => {
  const r = await fetch('/backend/graphql', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ query: q, variables: v }) });
  return r.json();
}, [query, variables]);

async function latestMailLink(to, pattern, since) {
  for (let i = 0; i < 30; i++) {
    const list = await (await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + to)}`)).json();
    for (const m of list.messages || []) {
      if (new Date(m.Created) < since) continue;
      const full = await (await fetch(`${MAILPIT}/api/v1/message/${m.ID}`)).json();
      const link = (full.Text.match(/https?:\/\/\S+/g) || []).find((u) => pattern.test(u));
      if (link) return link.replace(/[)>\].,]+$/, '');
    }
    await sleep(500);
  }
  throw new Error(`no e-mail for ${to} matching ${pattern} in Mailpit`);
}

async function signIn(page, email, url = PORTAL + '/') {
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  const me = await gql(page, '{ me { email } }').catch(() => null);
  if (me?.data?.me?.email === email && !url.includes('/admin')) return; // already signed in (same origin as the admin dashboard)
  const since = new Date(Date.now() - 1000);
  if (url.endsWith('/admin') || url.includes('/admin/')) {
    await page.locator('button:has-text("Continue with Email"):visible').first().click();
    await page.waitForTimeout(800);
    await page.locator('input:visible').first().fill(email);
    await page.locator('button:has-text("Send"):visible').last().click();
  } else {
    await page.locator('button:has-text("Login"):visible').first().click();
    await page.getByText('Continue with Email').click();
    await page.locator('[role=dialog] input:visible').first().fill(email);
    await page.locator('[role=dialog] button:has-text("Send"):visible').last().click();
  }
  const link = await latestMailLink(email, /token=/, since);
  await page.goto(link, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2500);
}


// Hoppscotch tree labels have pointer-events:none; click their row at the label's position.
async function clickLabel(page, text) {
  const el = page.locator(`text="${text}" >> visible=true`).first();
  await el.waitFor({ timeout: 15000 });
  const box = await el.boundingBox();
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  await page.waitForTimeout(700);
}

// Team import keeps collection-level settings (auth, headers, variables) only in `data`
// (the app's own UI importer does this conversion itself).
const teamFormat = (c) => ({ ...c, data: JSON.stringify({ auth: c.auth, headers: c.headers, variables: c.variables || [], description: c.description ?? null, preRequestScript: c.preRequestScript || '', testScript: c.testScript || '' }), folders: c.folders.map(teamFormat) });

const countRequests = (cols) => cols.reduce((n, c) => n + (c.requests?.length || 0) + countRequests(c.folders || []), 0);

async function openWorkspace(page, name) {
  await page.locator('button:has-text("Workspace"):visible, button:has-text("' + TEAM + '"):visible').first().click().catch(() => {});
  await page.waitForTimeout(800);
  await clickLabel(page, name);
  await page.waitForTimeout(2000);
}

const browser = await chromium.launch();
const A = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
const B = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
const S = {};
try {
  await report.step('P0', 'Portal reachable and onboarded (e-mail sign-in)', 'http', async () => {
    const status = await (await fetch(PORTAL + '/backend/v1/onboarding/status')).json();
    assert(status.onboardingCompleted, 'run: node scripts/portal/onboard.mjs');
    return `${PORTAL} — onboarding completed`;
  });

  await report.step('P1', 'Developer A signs in to the portal admin dashboard (first user becomes administrator)', 'browser', async () => {
    await signIn(A, DEV_A, PORTAL + '/admin');
    await A.screenshot({ path: path.join(OUT, 'portal-admin.png') });
    const status = await (await fetch(PORTAL + '/backend/v1/onboarding/status')).json();
    return `admin dashboard: ${A.url().replace(PORTAL, '')}; onboarding can be re-run by anonymous callers: ${status.canReRunOnboarding}`;
  });

  await report.step('P2', 'Developers A and B sign in to the app with SEPARATE accounts (magic link)', 'browser', async () => {
    await signIn(A, DEV_A);
    await signIn(B, DEV_B);
    const a = await gql(A, '{ me { uid email } }'), b = await gql(B, '{ me { uid email } }');
    assert(a.data?.me?.email === DEV_A && b.data?.me?.email === DEV_B, JSON.stringify([a, b]).slice(0, 200));
    assert(a.data.me.uid !== b.data.me.uid, 'same account');
    S.uidA = a.data.me.uid; S.uidB = b.data.me.uid;
    return `${DEV_A} (${S.uidA.slice(0, 8)}…) and ${DEV_B} (${S.uidB.slice(0, 8)}…), separate browser profiles`;
  });

  await report.step('P3', `Developer A creates the "${TEAM}" workspace, imports all collections and team environments`, 'browser+graphql', async () => {
    const teams = await gql(A, '{ myTeams { id name } }');
    let team = teams.data.myTeams.find((t) => t.name === TEAM);
    if (!team) team = (await gql(A, 'mutation($n:String!){ createTeam(name:$n){ id name } }', { n: TEAM })).data.createTeam;
    S.team = team.id;
    // Synchronise: the three GENERATED collections are replaced by the current export of the
    // Bruno collections (other team collections are never touched).
    const roots = (await gql(A, 'query($t:ID!){ rootCollectionsOfTeam(teamID:$t){ id title } }', { t: S.team })).data.rootCollectionsOfTeam;
    for (const old of roots.filter((r) => COLLECTIONS.some((c) => c.name === r.title))) {
      await gql(A, 'mutation($c:ID!){ deleteCollection(collectionID:$c) }', { c: old.id });
    }
    const r = await gql(A, 'mutation($t:ID!,$j:String!){ importCollectionsFromJSON(teamID:$t, jsonString:$j) }', { t: S.team, j: JSON.stringify(COLLECTIONS.map(teamFormat)) });
    assert(r.data?.importCollectionsFromJSON === true, JSON.stringify(r).slice(0, 300));
    const envs = (await gql(A, 'query($t:ID!){ team(teamID:$t){ teamEnvironments { id name } } }', { t: S.team })).data.team.teamEnvironments;
    for (const env of ENVIRONMENTS) {
      if (!envs.some((e) => e.name === env.name)) {
        const vars = env.variables.map((v) => ({ key: v.key, initialValue: v.secret ? '' : v.initialValue, currentValue: v.secret ? '' : v.currentValue, secret: v.secret }));
        await gql(A, 'mutation($n:String!,$t:ID!,$v:String!){ createTeamEnvironment(name:$n, teamID:$t, variables:$v){ id } }', { n: env.name, t: S.team, v: JSON.stringify(vars) });
      }
    }
    const exported = JSON.parse((await gql(A, 'query($t:ID!){ exportCollectionsToJSON(teamID:$t) }', { t: S.team })).data.exportCollectionsToJSON);
    const total = countRequests(exported.filter((c) => COLLECTIONS.some((x) => x.name === c.name)));
    assert(total === countRequests(COLLECTIONS), `team has ${total} requests, expected ${countRequests(COLLECTIONS)}`);
    return `${COLLECTIONS.length} collections, ${total} requests (all Bruno requests, imported — not retyped); environments: ${ENVIRONMENTS.map((e) => e.name).join(', ')}`;
  });

  await report.step('P4', 'Developer A invites B; B accepts the e-mailed invitation in the UI', 'browser', async () => {
    const members = (await gql(A, 'query($t:ID!){ team(teamID:$t){ teamMembers { user { email } } } }', { t: S.team })).data.team.teamMembers;
    if (!members.some((m) => m.user.email === DEV_B)) {
      const since = new Date(Date.now() - 1000);
      const inv = await gql(A, 'mutation($t:ID!,$e:String!){ createTeamInvitation(teamID:$t, inviteeEmail:$e, inviteeRole:EDITOR){ id } }', { t: S.team, e: DEV_B });
      assert(inv.data?.createTeamInvitation?.id, JSON.stringify(inv).slice(0, 300));
      const link = await latestMailLink(DEV_B, /join-team|invite/i, since);
      await B.goto(link, { waitUntil: 'networkidle' });
      await B.waitForTimeout(2000);
      await B.screenshot({ path: path.join(OUT, 'portal-invite.png') });
      await B.locator('button:has-text("Join"):visible, button:has-text("Accept"):visible').first().click();
      await B.waitForTimeout(2500);
    }
    const mine = (await gql(B, '{ myTeams { id name myRole } }')).data.myTeams.find((t) => t.id === S.team);
    assert(mine, 'B is not a member');
    return `B is a member of "${TEAM}" with role ${mine.myRole}`;
  });

  await report.step('P5', 'Developer B sees the shared collections and environments in the UI', 'browser', async () => {
    await B.goto(PORTAL + '/', { waitUntil: 'networkidle' });
    await B.waitForTimeout(1500);
    await openWorkspace(B, TEAM);
    for (const c of COLLECTIONS) await B.getByText(c.name).first().waitFor({ timeout: 15000 });
    await B.screenshot({ path: path.join(OUT, 'portal-team-collections.png') });
    const envs = (await gql(B, 'query($t:ID!){ team(teamID:$t){ teamEnvironments { name } } }', { t: S.team })).data.team.teamEnvironments.map((e) => e.name);
    assert(ENVIRONMENTS.every((e) => envs.includes(e.name)), envs.join(','));
    return `${COLLECTIONS.map((c) => c.name).join(', ')} visible; team environments ${envs.join(', ')}`;
  });

  await report.step('P6', 'Developer B edits a shared request in the UI and saves it; A sees the change', 'browser', async () => {
    // Idempotent: A first resets the shared Ping request to its generated definition.
    const admin = (await gql(A, 'query($t:ID!){ rootCollectionsOfTeam(teamID:$t){ id title children { id title } } }', { t: S.team })).data.rootCollectionsOfTeam.find((c) => c.title === 'WAFFRLI — Admin API');
    const auth = admin.children.find((c) => c.title === 'Authentication');
    const ping = (await gql(A, 'query($c:ID!){ requestsInCollection(collectionID:$c){ id title } }', { c: auth.id })).data.requestsInCollection.find((r) => r.title === 'Ping (public health check)');
    const original = COLLECTIONS.find((c) => c.name === 'WAFFRLI — Admin API').folders.find((f) => f.name === 'Authentication').requests.find((r) => r.name === 'Ping (public health check)');
    await gql(A, 'mutation($r:ID!,$d:UpdateTeamRequestInput!){ updateRequest(requestID:$r, data:$d){ id } }', { r: ping.id, d: { title: original.name, request: JSON.stringify(original) } });
    await B.reload({ waitUntil: 'networkidle' });
    await B.waitForTimeout(1500);
    await clickLabel(B, 'WAFFRLI — Admin API');
    await clickLabel(B, 'Authentication');
    await clickLabel(B, 'Ping (public health check)');
    await B.waitForTimeout(1500);
    // Add a request header in the Headers tab, then save to the team collection.
    S.marker = 'e2e-' + Date.now();
    await clickLabel(B, 'Headers');
    // CodeMirror placeholders ignore pointer events: click the editor at their position.
    const clickPlaceholder = async (text, which) => {
      const ph = B.locator(`.cm-placeholder:text-is("${text}") >> visible=true`);
      const bx = await (which === 'first' ? ph.first() : ph.last()).boundingBox();
      await B.mouse.click(bx.x + 5, bx.y + bx.height / 2);
    };
    await clickPlaceholder('Key', 'last');
    await B.keyboard.type('X-Waffrli-Note');
    await clickPlaceholder('Value', 'first'); // typing the key adds a new empty row below
    await B.keyboard.type(S.marker);
    await B.keyboard.press('Control+s');
    await B.waitForTimeout(2500);
    await B.screenshot({ path: path.join(OUT, 'portal-edit-by-b.png') });
    let seen = false;
    for (let i = 0; i < 20 && !seen; i++) {
      seen = (await gql(A, 'query($t:ID!){ exportCollectionsToJSON(teamID:$t) }', { t: S.team })).data.exportCollectionsToJSON.includes(S.marker);
      if (!seen) await sleep(500);
    }
    assert(seen, 'A does not see B\'s saved change');
    return `B added header X-Waffrli-Note: ${S.marker} and saved; A's view of the shared workspace contains it`;
  });

  await report.step('P7', 'Developer B sends real requests from the portal; converted Bruno assertions pass', 'browser', async () => {
    // Select the team environment (base_url → the Docker API).
    await clickLabel(B, 'Select environment');
    await clickLabel(B, 'Workspace Environments');
    await clickLabel(B, 'WAFFRLI Local');
    await B.waitForTimeout(800);
    await B.keyboard.press('Escape');
    await B.locator('button:has-text("Send"):visible').first().click();
    await B.waitForFunction(() => /Status:?\s*200/.test(document.body.innerText), null, { timeout: 15000 });
    await B.waitForTimeout(1500);
    const text = (await B.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ');
    await B.screenshot({ path: path.join(OUT, 'portal-send.png') });
    assert(/"status"\s*:\s*"ok"|status.{0,4}ok/i.test(text), 'response body not shown');
    return 'GET <<base_url>>/api/v1/admin/ping → 200 {"status":"ok"} shown in B\'s browser';
  });
  await report.step('P8', 'Token management: B logs in with LOCAL secrets, the token is captured and used; nothing secret is synced to A', 'browser', async () => {
    if (!cfg.E2E_ADMIN_EMAIL || !cfg.E2E_ADMIN_PASSWORD) return { skip: 'E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD not set in e2e/.env' };
    // Environments sidebar → WAFFRLI Local → ⋮ → Edit → Secrets: fill the LOCAL "Current value".
    await B.locator('button[aria-label="Environments"] >> visible=true').first().click();
    await B.waitForTimeout(800);
    const row = B.locator('text="WAFFRLI Local" >> visible=true').last();
    const rb = await row.boundingBox();
    await B.mouse.move(rb.x + 10, rb.y + rb.height / 2);
    const menus = await B.$$eval('button', (bs, y) => bs.map((b, i) => [i, b.getBoundingClientRect()]).filter(([, r]) => Math.abs(r.y + r.height / 2 - y) < 14 && r.width > 0).map(([i]) => i), rb.y + rb.height / 2);
    await (await B.$$('button'))[menus[menus.length - 1]].click();
    await B.waitForTimeout(500);
    await B.keyboard.press('e');
    await B.waitForTimeout(1000);
    await clickLabel(B, 'Secrets');
    const fill = async (placeholder, value) => {
      const input = B.locator(`[role=dialog] input[placeholder="${placeholder}"] >> visible=true`).first();
      if (await input.count()) return input.fill(value);
      const ph = B.locator(`[role=dialog] .cm-placeholder:text-is("${placeholder}") >> visible=true`).first();
      const bx = await ph.boundingBox();
      await B.mouse.click(bx.x + 5, bx.y + bx.height / 2);
      await B.keyboard.type(value);
    };
    const secrets = await B.$$eval('[role=dialog] input', (is) => is.map((i) => i.value).filter(Boolean));
    const idx = (k) => secrets.indexOf(k) - secrets.indexOf('admin_email') + 1;
    await fill(`Current value ${idx('admin_email')}`, cfg.E2E_ADMIN_EMAIL);
    await fill(`Current value ${idx('admin_password')}`, cfg.E2E_ADMIN_PASSWORD);
    await B.locator('[role=dialog] button:has-text("Save") >> visible=true').first().click();
    await B.waitForTimeout(1500);
    await B.locator('button[aria-label="Collections"] >> visible=true').first().click();
    await B.waitForTimeout(800);

    const send = async (folder, request) => {
      if (!(await B.locator(`text="${request}" >> visible=true`).count())) await clickLabel(B, folder); // folders toggle
      await clickLabel(B, request);
      await B.waitForTimeout(800);
      await B.locator('button:has-text("Send") >> visible=true').first().click();
      await B.waitForFunction(() => /Status:?\s*\d{3}/.test(document.body.innerText), null, { timeout: 15000 });
      await B.waitForTimeout(2000);
      const status = Number(((await B.evaluate(() => document.body.innerText)).match(/Status:?\s*(\d{3})/) || [])[1]);
      await clickLabel(B, 'Test Results');
      await B.waitForTimeout(800);
      const results = (await B.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ');
      // The panel lists each assertion as "test passed" / "test failed" (and shows captured variables).
      return { status, passed: (results.match(/test passed/g) || []).length, failed: (results.match(/test failed/g) || []).length };
    };
    const login = await send('Authentication', 'Login (success)');
    assert(login.status === 200, `login → ${login.status}`);
    assert(login.failed === 0 && login.passed > 0, `login assertions: ${login.passed} passed, ${login.failed} failed`);
    let authHeader = '';
    const onReq = (r) => { if (r.url().includes('/api/v1/admin/cities/all')) authHeader = r.headers().authorization || ''; };
    B.on('request', onReq);
    const cities = await send('Cities', 'List all');
    B.off('request', onReq);
    assert(/^Bearer \d+\|/.test(authHeader), 'the captured token was not sent');
    await B.screenshot({ path: path.join(OUT, 'portal-token-and-tests.png') });
    assert(cities.status === 200, `cities with the captured token → ${cities.status}`);
    assert(cities.failed === 0 && cities.passed > 0, `cities assertions: ${cities.passed} passed, ${cities.failed} failed`);

    // What the server stores for the SHARED environment (what A receives): no secret values.
    const env = (await gql(A, 'query($t:ID!){ team(teamID:$t){ teamEnvironments { name variables } } }', { t: S.team })).data.team.teamEnvironments.find((e) => e.name === 'WAFFRLI Local');
    const leaked = JSON.parse(env.variables).filter((v) => v.secret && (v.initialValue || v.currentValue || v.value));
    assert(leaked.length === 0 && !env.variables.includes(cfg.E2E_ADMIN_PASSWORD), 'secret values reached the shared environment');
    return `Login (success) 200 (${login.passed} assertions passed) → admin_token captured by the converted script → Cities/List all 200 (${cities.passed} passed); A's shared environment holds no secret value`;
  });
} finally {
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'portal.md'), report.markdown({ base: PORTAL, reverb: '-' }));
  const c = report.counts();
  console.log(`\n${c.PASS} passed, ${c.FAIL} failed, ${c.SKIP} skipped → e2e/results/portal.md`);
  process.exitCode = c.FAIL ? 1 : 0;
}
