#!/usr/bin/env node

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Captures the screenshot set used by the documentation site.
 *
 * Not a .spec.js, so the E2E suite never picks it up. Run it against a
 * devcontainer instance and point OUT_DIR at the docs repo:
 *
 *   node playwright/tools/capture-docs-screenshots.js
 *   BASE_URL=http://localhost:8083 SHOTS=api node playwright/tools/capture-docs-screenshots.js
 *
 * Environment:
 *   BASE_URL  instance to shoot        (default http://localhost:8080)
 *   OUT_DIR   where PNGs are written   (default ../poweradmin-docs/docs/screenshots)
 *   SHOTS     comma-separated name filter, substring match (default: all)
 *   USERNAME / PASSWORD  login to use  (default admin / Poweradmin123)
 *
 * Zone-scoped shots resolve their zone id by name at runtime, so the set does
 * not break when test data is reimported with different ids.
 *
 * Before a publishable run, set `interface.title` to 'Poweradmin' and
 * `interface.application_url` in the instance's settings file, then restart the
 * PHP container so opcache picks it up. Otherwise every shot carries the
 * devcontainer's own title and a dashboard warning banner.
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const BASE_URL = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const OUT_DIR = process.env.OUT_DIR
  || path.join(__dirname, '..', '..', '..', 'poweradmin-docs', 'docs', 'screenshots');
const FILTER = (process.env.SHOTS || '').split(',').map(s => s.trim()).filter(Boolean);
const USERNAME = process.env.USERNAME || 'admin';
const PASSWORD = process.env.PASSWORD || 'Poweradmin123';

// Fixed viewport so the whole set crops identically, sized to match the
// screenshots already in the docs repo (roughly 1710x912, no HiDPI scaling).
const VIEWPORT = { width: 1700, height: 950 };

// A full-page shot of a long list runs to thousands of pixels and is useless on
// a docs page, so full-page captures are clipped to this height.
const MAX_FULL_HEIGHT = 2000;

const ZONE = 'manager-zone.example.com';

/**
 * Shot list. `url` may be a string or a function receiving resolved ids.
 * `prep` runs after navigation and before the capture (open a tab, expand a
 * panel). `full` captures the entire scrollable page instead of the viewport.
 */
const SHOTS = [
  // Dashboard and zone lists
  { name: 'dashboard', url: '/' },
  { name: 'zone-list', url: '/zones/forward?letter=a' },
  { name: 'zone-list-reverse', url: '/zones/reverse?letter=a' },
  { name: 'search', url: '/search' },

  // Zone editing
  { name: 'zone-editor', url: ids => `/zones/${ids.zone}/edit`, full: true },
  { name: 'zone-metadata-editor', url: ids => `/zones/${ids.zone}/metadata`, full: true },
  { name: 'zone-ownership', url: ids => `/zones/${ids.zone}/ownership` },
  { name: 'zone-add-master', url: '/zones/add/master', full: true },
  { name: 'zone-save-as-template', url: ids => `/zones/${ids.zone}/save-template` },

  // Bulk and batch
  { name: 'bulk-record-add', url: ids => `/zones/${ids.zone}/records/bulk`, full: true },
  { name: 'ptr-batch-interface', url: '/zones/batch-ptr', full: true },
  { name: 'bulk-registration', url: '/zones/bulk-registration', full: true },

  // Templates
  { name: 'zone-template-list', url: '/zones/templates' },

  // DNS wizards (module)
  { name: 'dns-wizard-select', url: ids => `/zones/${ids.zone}/wizard` },
  { name: 'dns-wizard-dmarc', url: ids => `/zones/${ids.zone}/wizard/DMARC`, full: true },

  // DNSSEC
  { name: 'dnssec-overview', url: ids => `/zones/${ids.zone}/dnssec` },
  { name: 'dnssec-ds-dnskey', url: ids => `/zones/${ids.zone}/dnssec/ds-dnskey`, full: true },

  // Logs and change tracking
  { name: 'record-change-log', url: '/zones/changes', full: true },
  { name: 'zone-logs', url: '/zones/logs', full: true },
  { name: 'user-logs', url: '/users/logs', full: true },
  { name: 'group-logs', url: '/groups/logs', full: true },
  { name: 'api-logs', url: '/settings/api/logs', full: true },

  // Users, groups, permissions
  { name: 'user-list', url: '/users' },
  { name: 'group-list', url: '/groups' },
  { name: 'group-members', url: ids => `/groups/${ids.group}/members` },
  { name: 'group-zones', url: ids => `/groups/${ids.group}/zones` },
  { name: 'permission-template-list', url: '/permissions/templates' },
  { name: 'permission-template-edit', url: ids => `/permissions/templates/${ids.permTemplate}/edit`, full: true },
  { name: 'user-preferences', url: '/user/preferences', full: true },
  { name: 'mfa-setup', url: '/mfa/setup', full: true },

  // API keys
  { name: 'api-key-list', url: '/settings/api-keys' },
  { name: 'api-key-add', url: '/settings/api-keys/add', full: true },

  // Tools and modules
  { name: 'record-type-defaults', url: '/tools/record-type-defaults', full: true },
  { name: 'database-consistency', url: '/tools/database-consistency', full: true },
  // Queries the PowerDNS API on render, so it needs longer than the rest.
  { name: 'pdns-status', url: '/tools/pdns-status', full: true, timeout: 90000 },
  { name: 'zone-import', url: '/tools/zone-import', full: true },
  { name: 'email-previews', url: '/tools/email-previews' },
  { name: 'whois-lookup', url: '/whois' },
  { name: 'rdap-lookup', url: '/rdap' },
  { name: 'supermasters', url: '/supermasters' },

  // API-backend only. Run with BASE_URL pointed at an API-mode instance.
  { name: 'secondary-zone-import', url: '/zones/import-secondary', full: true, apiBackendOnly: true },
  { name: 'zone-list-api-backend', url: '/zones/forward?letter=a', apiBackendOnly: true },

  // PowerDNS 5.0 only. Skipped automatically when the routes are absent.
  { name: 'views-list', url: '/views' },
  { name: 'networks-list', url: '/networks' },
];

async function login(page) {
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('[data-testid="username-input"]', USERNAME);
  await page.fill('[data-testid="password-input"]', PASSWORD);
  await page.click('[data-testid="login-button"]');
  await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 15000 });
}

/**
 * Resolve the ids the shot list needs by reading them out of the UI, so the
 * set survives a test-data reimport that renumbers rows.
 */
async function resolveIds(page) {
  const ids = {};

  await page.goto(`${BASE_URL}/zones/forward`, { waitUntil: 'domcontentloaded' });
  const zoneLink = page.locator(`a[href*="/zones/"][href$="/edit"]`).filter({ hasText: ZONE }).first();
  if (await zoneLink.count() > 0) {
    ids.zone = (await zoneLink.getAttribute('href')).match(/\/zones\/(\d+)\/edit/)[1];
  } else {
    const anyZone = page.locator('a[href*="/zones/"][href$="/edit"]').first();
    ids.zone = (await anyZone.getAttribute('href')).match(/\/zones\/(\d+)\/edit/)[1];
  }

  await page.goto(`${BASE_URL}/groups`, { waitUntil: 'domcontentloaded' });
  const groupLink = page.locator('a[href*="/groups/"][href$="/members"]').first();
  ids.group = (await groupLink.count() > 0)
    ? (await groupLink.getAttribute('href')).match(/\/groups\/(\d+)\/members/)[1]
    : null;

  await page.goto(`${BASE_URL}/permissions/templates`, { waitUntil: 'domcontentloaded' });
  const permLink = page.locator('a[href*="/permissions/templates/"][href$="/edit"]').first();
  ids.permTemplate = (await permLink.count() > 0)
    ? (await permLink.getAttribute('href')).match(/\/permissions\/templates\/(\d+)\/edit/)[1]
    : null;

  return ids;
}

/**
 * Close transient notices so they do not end up in the documentation. Only
 * dismissible alerts are touched: the explanatory info boxes that belong to a
 * page carry no close button and survive this.
 */
async function dismissNotices(page) {
  const closers = page.locator('.alert .btn-close');
  for (let i = await closers.count(); i > 0; i--) {
    const closer = closers.first();
    if (await closer.isVisible().catch(() => false)) {
      await closer.click().catch(() => {});
      await page.waitForTimeout(150);
    }
  }
}

async function capture(page, shot, ids) {
  const url = typeof shot.url === 'function' ? shot.url(ids) : shot.url;

  // A shot whose id could not be resolved has nothing to point at.
  if (url.includes('/null/') || url.includes('undefined')) {
    return { name: shot.name, status: 'skipped', reason: 'id not resolved' };
  }

  // Pages that poll a backend never reach networkidle, so wait on the DOM and
  // give the page a fixed moment to settle instead.
  const response = await page.goto(`${BASE_URL}${url}`, {
    waitUntil: 'domcontentloaded',
    timeout: shot.timeout || 20000,
  });
  await page.waitForTimeout(1200);

  if (shot.prep) {
    await shot.prep(page);
    await page.waitForTimeout(800);
  }

  await dismissNotices(page);

  const status = response ? response.status() : 0;
  const landed = new URL(page.url()).pathname;

  // A route the build does not serve redirects to the dashboard or 404s
  // rather than erroring, so compare where we landed with where we aimed.
  const aimed = url.split('?')[0];
  if (status >= 400) {
    return { name: shot.name, status: 'skipped', reason: `HTTP ${status}` };
  }
  if (landed !== aimed && !landed.startsWith(aimed)) {
    return { name: shot.name, status: 'skipped', reason: `redirected to ${landed}` };
  }

  // A capability-gated page renders only an error banner on an older PowerDNS.
  // That is not a screenshot worth shipping.
  const banner = page.locator('.alert-danger').first();
  if (await banner.count() > 0) {
    const text = (await banner.innerText()).trim().replace(/\s+/g, ' ');
    if (/require/i.test(text)) {
      return { name: shot.name, status: 'skipped', reason: text.slice(0, 60) };
    }
  }

  const file = path.join(OUT_DIR, `${shot.name}.png`);
  if (shot.full) {
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    if (height > MAX_FULL_HEIGHT) {
      await page.screenshot({
        path: file,
        clip: { x: 0, y: 0, width: VIEWPORT.width, height: MAX_FULL_HEIGHT },
      });
      return { name: shot.name, status: 'ok', file, note: `clipped from ${height}px` };
    }
  }
  await page.screenshot({ path: file, fullPage: Boolean(shot.full) });
  return { name: shot.name, status: 'ok', file };
}

/**
 * Shoot the login page from a fresh context, before any session exists.
 *
 * An instance still carrying the install/ directory refuses to render the form
 * and shows an error instead, which is not a screenshot worth shipping, so bail
 * out rather than overwrite a good capture with the error page.
 */
async function captureLogin(browser) {
  const context = await browser.newContext({ viewport: VIEWPORT });
  const page = await context.newPage();
  try {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);

    if (await page.locator('[data-testid="username-input"]').count() === 0) {
      const banner = page.locator('.alert-danger').first();
      const reason = await banner.count() > 0
        ? (await banner.innerText()).trim().replace(/\s+/g, ' ').slice(0, 60)
        : 'login form not rendered';
      return { name: 'login', status: 'skipped', reason };
    }

    const file = path.join(OUT_DIR, 'login.png');
    await page.screenshot({ path: file });
    return { name: 'login', status: 'ok', file };
  } finally {
    await context.close();
  }
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: VIEWPORT });
  const page = await context.newPage();

  const results = [];
  try {
    // The login page has to be shot before authenticating: once a session
    // exists /login redirects to the dashboard. Use a throwaway context so the
    // main one stays signed in for everything else.
    if (FILTER.length === 0 || FILTER.some(f => 'login'.includes(f))) {
      results.push(await captureLogin(browser));
      const last = results[results.length - 1];
      console.log(
        last.status === 'ok' ? `  ok       ${last.name}` : `  skipped  ${last.name} (${last.reason})`
      );
    }

    await login(page);
    const ids = await resolveIds(page);
    console.log(`Resolved ids: ${JSON.stringify(ids)}`);

    const wanted = SHOTS.filter(s => FILTER.length === 0 || FILTER.some(f => s.name.includes(f)));
    for (const shot of wanted) {
      try {
        const result = await capture(page, shot, ids);
        results.push(result);
        console.log(
          result.status === 'ok'
            ? `  ok       ${result.name}${result.note ? ` (${result.note})` : ''}`
            : `  skipped  ${result.name} (${result.reason})`
        );
      } catch (err) {
        results.push({ name: shot.name, status: 'failed', reason: err.message.split('\n')[0] });
        console.log(`  failed   ${shot.name} (${err.message.split('\n')[0]})`);
      }
    }
  } finally {
    await browser.close();
  }

  const ok = results.filter(r => r.status === 'ok').length;
  console.log(`\n${ok}/${results.length} captured into ${OUT_DIR}`);
  const problems = results.filter(r => r.status === 'failed');
  process.exit(problems.length > 0 ? 1 : 0);
}

main();
