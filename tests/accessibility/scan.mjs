#!/usr/bin/env node
/**
 * Automated axe WCAG 2.1 AA scan (issue #52).
 *
 * Visits every covered scenario in scenarios.json with Playwright/Chromium,
 * runs axe-core with the wcag2a/wcag2aa/wcag21a/wcag21aa tags, and fails on
 * any violation. Results identify the route/state and the violation.
 *
 * Usage (see docs/accessibility.md):
 *   BASE_URL=http://127.0.0.1:8000 \
 *   A11Y_USER_EMAIL=foo@bar.com A11Y_USER_PASSWORD=alamakota \
 *   node tests/accessibility/scan.mjs
 *
 * Exit codes: 0 = zero violations, 1 = violations or route failures,
 * 2 = setup error (server unreachable, login failed, nothing scanned).
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const BASE_URL = (process.env.BASE_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
const REPORT_PATH = resolve(ROOT, process.env.REPORT_PATH ?? 'var/accessibility-report.json');
const EMAIL = process.env.A11Y_USER_EMAIL;
const PASSWORD = process.env.A11Y_USER_PASSWORD;

const registry = JSON.parse(readFileSync(resolve(ROOT, 'tests/accessibility/scenarios.json'), 'utf8'));
const VIEWPORTS = registry.viewports;
const TAGS = registry.wcagTags;

/* Minimal cookie jar over fetch: the setup requests (login, fixture
 * creation) run outside the browser, then the session is injected into
 * each authenticated Playwright context. */
const jar = new Map();
function storeCookies(res) {
  for (const raw of res.headers.getSetCookie?.() ?? []) {
    const [pair] = raw.split(';');
    const eq = pair.indexOf('=');
    if (eq > 0) jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
  }
}
function cookieHeader() {
  return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}
async function http(url, { method = 'GET', body = null } = {}) {
  let current = url;
  for (let i = 0; i < 5; i++) {
    const res = await fetch(current, {
      method,
      body,
      redirect: 'manual',
      headers: {
        ...(jar.size ? { Cookie: cookieHeader() } : {}),
        ...(body ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}),
      },
    });
    storeCookies(res);
    if ([301, 302, 303, 307, 308].includes(res.status) && res.headers.get('location')) {
      current = new URL(res.headers.get('location'), current).toString();
      method = 'GET';
      body = null;
      continue;
    }
    const html = await res.text();
    return { res, url: current, html };
  }
  throw new Error(`Too many redirects for ${url}`);
}

async function login() {
  const { res, html } = await http(`${BASE_URL}/login`);
  if (!res.ok) throw new Error(`GET /login -> ${res.status}; is the server running at ${BASE_URL}?`);
  const csrf = html.match(/name="_csrf_token"\s+value="([^"]+)"/)?.[1];
  if (!csrf) throw new Error('Login form CSRF token not found.');
  const { url } = await http(`${BASE_URL}/login`, {
    method: 'POST',
    body: new URLSearchParams({ email: EMAIL, password: PASSWORD, _csrf_token: csrf }),
  });
  if (new URL(url).pathname === '/login') {
    throw new Error(`Login failed for ${EMAIL}; check credentials and fixtures.`);
  }
}

async function resolveSubscriptionId() {
  const { html } = await http(`${BASE_URL}/dashboard`);
  const found = html.match(/\/subscription\/([^"\/]+)\/edit/);
  if (found) return found[1];

  // No subscription yet: create one through the form so edit/delete states exist.
  const { html: formHtml } = await http(`${BASE_URL}/subscription/new`);
  const token = formHtml.match(/name="subscription\[_token\]"[^>]*value="([^"]+)"/)?.[1];
  if (!token) throw new Error('Subscription form token not found; cannot seed scan fixture.');
  await http(`${BASE_URL}/subscription/new`, {
    method: 'POST',
    body: new URLSearchParams({
      'subscription[name]': 'Axe scan fixture',
      'subscription[firstPayment]': '2026-01-01',
      'subscription[monthly]': '9.99',
      'subscription[yearly]': '',
      'subscription[_token]': token,
    }),
  });
  const { html: retryHtml } = await http(`${BASE_URL}/dashboard`);
  const id = retryHtml.match(/\/subscription\/([^"\/]+)\/edit/)?.[1];
  if (!id) throw new Error('Could not create a subscription fixture for dynamic scenarios.');
  return id;
}

const failures = [];
const passes = [];
const skipped = [];

function report(type, line, extra = '') {
  console.log(`[a11y] ${type} ${line}${extra}`);
}

async function scanScenario(browser, scenario, subscriptionId) {
  for (const viewportName of scenario.viewports) {
    const label = `${scenario.id} (${scenario.path}, ${viewportName})`;
    const context = await browser.newContext({
      viewport: VIEWPORTS[viewportName],
      ...(scenario.auth ? { extraHTTPHeaders: { Cookie: cookieHeader() } } : {}),
    });
    const page = await context.newPage();
    try {
      const path = scenario.path.replace('{id}', subscriptionId ?? '');
      const response = await page.goto(`${BASE_URL}${path}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const status = response?.status() ?? 0;

      if (status === 404 && scenario.pending) {
        skipped.push(label);
        report('SKIP', `${label}: 404, surface not implemented yet (${scenario.issue}).`);
        continue;
      }
      if (status !== 200) {
        failures.push({ scenario: label, error: `HTTP ${status}` });
        report('FAIL', `${label}: HTTP ${status}.`);
        continue;
      }
      if (new URL(page.url()).pathname === '/login' && scenario.auth) {
        failures.push({ scenario: label, error: 'redirected to /login: session missing' });
        report('FAIL', `${label}: landed on /login, not authenticated.`);
        continue;
      }

      // Let Turbo/Stimulus/Chart.js settle; analytics beacons must not block us.
      await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(1200);

      const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
      if (results.violations.length === 0) {
        passes.push(label);
        report('PASS', label);
      } else {
        failures.push({ scenario: label, violations: results.violations });
        report('FAIL', `${label}: ${results.violations.length} violation(s).`);
        for (const v of results.violations) {
          console.log(`       - ${v.id} (${v.impact}): ${v.help}`);
          console.log(`         ${v.helpUrl}`);
          for (const node of v.nodes.slice(0, 3)) {
            console.log(`         target: ${node.target.join(', ')}`);
          }
          if (v.nodes.length > 3) console.log(`         ... and ${v.nodes.length - 3} more node(s)`);
        }
      }
    } catch (error) {
      failures.push({ scenario: label, error: String(error?.message ?? error) });
      report('FAIL', `${label}: ${error?.message ?? error}`);
    } finally {
      await context.close();
    }
  }
}

const covered = registry.scenarios.filter((s) => !s.pending);
const pending = registry.scenarios.filter((s) => s.pending);

if (!EMAIL || !PASSWORD) {
  if (covered.some((s) => s.auth)) {
    console.error('[a11y] A11Y_USER_EMAIL and A11Y_USER_PASSWORD are required for authenticated scenarios.');
    process.exit(2);
  }
}

let browser;
try {
  await http(`${BASE_URL}/`).then(({ res }) => {
    if (!res.ok) throw new Error(`Server at ${BASE_URL} returned ${res.status}.`);
  });
} catch (error) {
  console.error(`[a11y] Server unreachable at ${BASE_URL}: ${error.message}`);
  process.exit(2);
}

try {
  if (covered.some((s) => s.auth)) {
    await login();
    report('INFO', `authenticated as ${EMAIL}.`);
  }
  const needsId = covered.some((s) => (s.path ?? '').includes('{id}'));
  const subscriptionId = needsId ? await resolveSubscriptionId() : null;

  browser = await chromium.launch();
  for (const scenario of covered) {
    await scanScenario(browser, scenario, subscriptionId);
  }
  for (const scenario of pending) {
    if (!scenario.path) {
      skipped.push(scenario.id);
      report('SKIP', `${scenario.id}: surface not implemented yet (${scenario.issue}).`);
      continue;
    }
    // Pending with a known path: warn if it quietly went live.
    const { res } = await http(`${BASE_URL}${scenario.path}`);
    if (res.ok) {
      failures.push({ scenario: scenario.id, error: 'pending surface now resolves: promote to covered' });
      report('FAIL', `${scenario.id}: pending but ${scenario.path} now resolves; promote it in scenarios.json.`);
    } else {
      skipped.push(scenario.id);
      report('SKIP', `${scenario.id}: 404, surface not implemented yet (${scenario.issue}).`);
    }
  }
} catch (error) {
  console.error(`[a11y] Setup error: ${error.message}`);
  process.exit(2);
} finally {
  await browser?.close();
}

mkdirSync(dirname(REPORT_PATH), { recursive: true });
writeFileSync(REPORT_PATH, JSON.stringify({ baseUrl: BASE_URL, passes, failures, skipped }, null, 2));

console.log(`[a11y] summary: ${passes.length} passed, ${failures.length} failed, ${skipped.length} skipped. Report: ${REPORT_PATH}`);
if (passes.length === 0 && failures.length === 0) {
  console.error('[a11y] Nothing was scanned.');
  process.exit(2);
}
process.exit(failures.length ? 1 : 0);
