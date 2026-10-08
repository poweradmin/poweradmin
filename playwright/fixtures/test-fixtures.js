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
 *   1. Writes go to throwaway objects from these helpers (tempZone, workerZone,
 *      createTempZone, uniqueName/uniqueZoneName in helpers/zones.js), never to
 *      the seeded zones/users in fixtures/zones.json and users.json (read-only).
 *   2. Never assert exact counts on shared pages (zone lists, user lists, logs);
 *      scope assertions to your own object, e.g. /zones/{id}/edit?search=<label>
 *      or a row filtered by its unique name.
 *   3. Clean up in teardown (fixtures do this even when the test failed).
 *
 * Fixtures:
 *   tempZone    (test scope)   fresh zone + apex NS per test: { id, name }
 *   workerZone  (worker scope) one zone shared by all tests a worker runs in a
 *                              file; use with serial describe blocks
 * Both are created in a separate admin browser context, so the test's own page
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
  tempZone: async ({ browser, baseURL }, use, testInfo) => {
    const zone = await withAdminPage(browser, baseURL, page =>
      createTempZone(page, { name: uniqueZoneName('tmp', testInfo) }));
    try {
      await use(zone);
    } finally {
      await withAdminPage(browser, baseURL, page => deleteZoneById(page, zone.id));
    }
  },

  workerZone: [async ({ browser }, use, workerInfo) => {
    const baseURL = workerInfo.project.use.baseURL ?? process.env.BASE_URL;
    const zone = await withAdminPage(browser, baseURL, page =>
      createTempZone(page, { name: uniqueZoneName('wrk', { workerIndex: workerInfo.workerIndex }) }));
    try {
      await use(zone);
    } finally {
      await withAdminPage(browser, baseURL, page => deleteZoneById(page, zone.id));
    }
  }, { scope: 'worker' }],

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

// Re-export expect for convenience
export { expect };

// Re-export users for tests that need user data
export { users };
