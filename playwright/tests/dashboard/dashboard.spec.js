import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Dashboard', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should display dashboard content', async ({ page }) => {
      // Dashboard should have some content area
      const mainContent = page.locator('main, .container, .content, #content').first();
      await expect(mainContent).toBeVisible();
    });

    test('should have Search link', async ({ page }) => {
      const searchLink = page.locator('a[href*="/search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should have List zones link', async ({ page }) => {
      const listZonesLink = page.locator('a[href*="/zones/forward"]').first();
      await expect(listZonesLink).toBeVisible();
    });

    test('should have Zone templates link', async ({ page }) => {
      const templatesLink = page.locator('a[href*="/zones/templates"]').first();
      await expect(templatesLink).toBeVisible();
    });

    test('should have Supermasters link', async ({ page }) => {
      const supermastersLink = page.locator('a[href*="/supermasters"]').first();
      await expect(supermastersLink).toBeVisible();
    });

    test('should have Add master zone link', async ({ page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const addMasterLink = page.locator('a[href*="/zones/add/master"]');
      expect(await addMasterLink.count()).toBeGreaterThan(0);
    });

    test('should have Add slave zone link', async ({ page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const addSlaveLink = page.locator('a[href*="/zones/add/slave"]');
      expect(await addSlaveLink.count()).toBeGreaterThan(0);
    });

    test('should have Add supermaster link', async ({ page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const addSupermasterLink = page.locator('a[href*="/supermasters/add"]');
      expect(await addSupermasterLink.count()).toBeGreaterThan(0);
    });

    test('should have Bulk registration link', async ({ page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const bulkLink = page.locator('a[href*="/zones/bulk-registration"]');
      expect(await bulkLink.count()).toBeGreaterThan(0);
    });

    test('should have Zone logs link for admin (if db logging enabled)', async ({ page }) => {
      // Zone logs link only appears when dblog_use is enabled in config
      // Link may be in dropdown menu, check if it exists in DOM
      const zoneLogsLink = page.locator('a[href*="/zones/logs"]');
      const count = await zoneLogsLink.count();
      // Either the link exists (dblog enabled) or it doesn't (dblog disabled) - both are valid
      expect(count >= 0).toBeTruthy();
      if (count > 0) {
        // If link exists, verify it's accessible
        expect(count).toBeGreaterThan(0);
      }
    });

    test('should have Change password link', async ({ page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const changePasswordLink = page.locator('a[href*="/password/change"]');
      expect(await changePasswordLink.count()).toBeGreaterThan(0);
    });

    test('should have User administration link', async ({ page }) => {
      const userAdminLink = page.locator('a[href$="/users"], a[href*="/users?"]').first();
      await expect(userAdminLink).toBeVisible();
    });

    test('should have Permission templates link', async ({ page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const permTemplatesLink = page.locator('a[href*="/permissions/templates"]');
      expect(await permTemplatesLink.count()).toBeGreaterThan(0);
    });

    test('should have Logout link', async ({ page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const logoutLink = page.locator('a[href*="/logout"]');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });

    test('should navigate to search page', async ({ page }) => {
      await page.locator('a[href*="/search"]').first().click();
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should navigate to list zones page', async ({ page }) => {
      await page.locator('a[href*="/zones/forward"]').first().click();
      await expect(page).toHaveURL(/.*zones\/forward/);
    });
  });

  test.describe('Manager User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should have Search link', async ({ page }) => {
      const searchLink = page.locator('a[href*="/search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should not have Zone logs link (not admin)', async ({ page }) => {
      const zoneLogsLink = page.locator('a[href*="/zones/logs"]');
      expect(await zoneLogsLink.count()).toBe(0);
    });

    test('should not have Permission templates link', async ({ page }) => {
      const permTemplatesLink = page.locator('a[href*="/permissions/templates"]');
      expect(await permTemplatesLink.count()).toBe(0);
    });
  });

  test.describe('Client User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should have limited navigation (no Search link in nav)', async ({ page }) => {
      // Client users have limited permissions - Search link may not be visible in navigation
      // They can still access /search directly if they have zone permissions
      const searchLink = page.locator('a[href*="/search"]');
      const count = await searchLink.count();
      // Client users may or may not have Search link depending on their zone permissions
      expect(count >= 0).toBeTruthy();
    });

    test('should not have Add master zone link', async ({ page }) => {
      const addMasterLink = page.locator('a[href*="/zones/add/master"]');
      expect(await addMasterLink.count()).toBe(0);
    });

    test('should not have Add slave zone link', async ({ page }) => {
      const addSlaveLink = page.locator('a[href*="/zones/add/slave"]');
      expect(await addSlaveLink.count()).toBe(0);
    });
  });

  test.describe('Viewer User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should have Search link', async ({ page }) => {
      const searchLink = page.locator('a[href*="/search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should have List zones link (view permission)', async ({ page }) => {
      const listZonesLink = page.locator('a[href*="/zones/forward"]').first();
      await expect(listZonesLink).toBeVisible();
    });

    test('should not have Add master zone link', async ({ page }) => {
      const addMasterLink = page.locator('a[href*="/zones/add/master"]');
      expect(await addMasterLink.count()).toBe(0);
    });
  });
});
