/**
 * Custom Playwright test fixtures for Poweradmin E2E tests
 *
 * These fixtures provide pre-authenticated page objects for different user types,
 * eliminating the need for beforeEach login patterns in test files.
 *
 * Usage:
 *   import { test, expect } from '../fixtures/test-fixtures.js';
 *
 *   test('admin can create zone', async ({ adminPage }) => {
 *     await adminPage.goto('/zones/add/master');
 *     // Already logged in as admin
 *   });
 *
 * Adapted for master branch modern URLs.
 *
 * ISOLATION RULES (every spec file must pass alone, rerun without a data reset,
 * in any order, with 2+ workers):
 *   1. Writes go to throwaway objects from these helpers (tempZone, useFileZone,
 *      createTempZone, uniqueName/uniqueZoneName in helpers/zones.js), never to
 *      the seeded zones/users in fixtures/zones.json and users.json (read-only).
 *   2. Never assert exact counts on shared pages (zone lists, user lists, logs);
 *      scope assertions to your own object, e.g. /zones/{id}/edit?search=<label>
 *      or a row filtered by its unique name.
 *   3. Clean up in teardown (fixtures do this even when the test failed).
 *
 * Throwaway zones:
 *   tempZone       (test fixture) fresh zone + apex NS per test: { id, name }
 *   useFileZone()  one zone per spec file or describe block, created in
 *                  beforeAll and deleted in afterAll; call it at file or
 *                  describe level: `const zone = useFileZone();`
 * There is deliberately no worker-scoped zone: Playwright shares worker
 * fixtures across every file a worker runs, so state would leak between files.
 * Zones are created in a separate admin browser context, so the test's own page
 * and session are untouched. Signed zones are deleted directly (no unsigning).
 */

import { test as base, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../helpers/auth.js';
import { createTempZone, deleteZoneById, uniqueZoneName } from '../helpers/zones.js';
import users from './users.json' with { type: 'json' };

/**
 * Extended test object with authenticated page fixtures
 */
async function withAdminPage(browser, baseURL, fn) {
  const context = await browser.newContext({ baseURL });
  try {
    const page = await context.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password, 3, { fresh: true });
    return await fn(page);
  } finally {
    await context.close();
  }
}

export const test = base.extend({
  // Own timeout so a slow teardown is not cut short by the test's budget and leaks the zone
  tempZone: [async ({ browser, baseURL }, use, testInfo) => {
    const zone = await withAdminPage(browser, baseURL, page =>
      createTempZone(page, { name: uniqueZoneName('tmp', testInfo) }));
    try {
      await use(zone);
    } finally {
      await withAdminPage(browser, baseURL, page => deleteZoneById(page, zone.id));
    }
  }, { timeout: 60_000 }],

  /**
   * Page authenticated as admin user
   * Use for tests requiring full system access
   */
  adminPage: async ({ page }, use) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await use(page);
  },

  /**
   * Page authenticated as manager user
   * Use for tests requiring zone management access
   */
  managerPage: async ({ page }, use) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await use(page);
  },

  /**
   * Page authenticated as client user
   * Use for tests requiring limited edit access
   */
  clientPage: async ({ page }, use) => {
    await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    await use(page);
  },

  /**
   * Page authenticated as viewer user
   * Use for tests requiring read-only access
   */
  viewerPage: async ({ page }, use) => {
    await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    await use(page);
  },

  /**
   * Page authenticated as noperm user
   * Use for tests requiring minimal/no permissions
   */
  nopermPage: async ({ page }, use) => {
    await loginAndWaitForDashboard(page, users.noperm.username, users.noperm.password);
    await use(page);
  },
});

/**
 * Give the calling spec file (or describe block) its own throwaway zone.
 * The returned object is filled in by beforeAll; read zone.id inside tests.
 *
 * @param {string} prefix - label prefix for the generated zone name
 * @returns {{ id: string|null, name: string|null }}
 */
export function useFileZone(prefix = 'file') {
  const zone = { id: null, name: null };
  const baseURLOf = testInfo => testInfo.project.use.baseURL ?? process.env.BASE_URL;

  test.beforeAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(testInfo.timeout + 60_000);
    Object.assign(zone, await withAdminPage(browser, baseURLOf(testInfo), page =>
      createTempZone(page, { name: uniqueZoneName(prefix, testInfo) })));
  });

  test.afterAll(async ({ browser }, testInfo) => {
    if (!zone.id) {
      return;
    }
    testInfo.setTimeout(testInfo.timeout + 60_000);
    await withAdminPage(browser, baseURLOf(testInfo), page => deleteZoneById(page, zone.id));
  });

  return zone;
}

// Re-export expect for convenience
export { expect };

// Re-export users for tests that need user data
export { users };
