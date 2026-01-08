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
 *     await adminPage.goto('/index.php?page=add_zone_master');
 *     // Already logged in as admin
 *   });
 */

import { test as base, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../helpers/auth.js';
import users from './users.json' assert { type: 'json' };

/**
 * Extended test object with authenticated page fixtures
 */
export const test = base.extend({
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
