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
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should have List zones link', async ({ page }) => {
      const listZonesLink = page.locator('a[href*="page=list_zones"]').first();
      await expect(listZonesLink).toBeVisible();
    });

    test('should have Zone templates link', async ({ page }) => {
      const templatesLink = page.locator('a[href*="page=list_zone_templ"]').first();
      await expect(templatesLink).toBeVisible();
    });

    test('should have Supermasters link', async ({ page }) => {
      const supermastersLink = page.locator('a[href*="page=list_supermasters"]').first();
      await expect(supermastersLink).toBeVisible();
    });

    test('should have Add master zone link', async ({ page }) => {
      const addMasterLink = page.locator('a[href*="page=add_zone_master"]').first();
      await expect(addMasterLink).toBeVisible();
    });

    test('should have Add slave zone link', async ({ page }) => {
      const addSlaveLink = page.locator('a[href*="page=add_zone_slave"]').first();
      await expect(addSlaveLink).toBeVisible();
    });

    test('should have Add supermaster link', async ({ page }) => {
      const addSupermasterLink = page.locator('a[href*="page=add_supermaster"]').first();
      await expect(addSupermasterLink).toBeVisible();
    });

    test('should have Bulk registration link', async ({ page }) => {
      const bulkLink = page.locator('a[href*="page=bulk_registration"]').first();
      await expect(bulkLink).toBeVisible();
    });

    test('should have Zone logs link for admin', async ({ page }) => {
      const zoneLogsLink = page.locator('a[href*="page=list_log_zones"]').first();
      await expect(zoneLogsLink).toBeVisible();
    });

    test('should have Change password link', async ({ page }) => {
      const changePasswordLink = page.locator('a[href*="page=change_password"]').first();
      await expect(changePasswordLink).toBeVisible();
    });

    test('should have User administration link', async ({ page }) => {
      const userAdminLink = page.locator('a[href*="page=users"]').first();
      await expect(userAdminLink).toBeVisible();
    });

    test('should have Permission templates link', async ({ page }) => {
      const permTemplatesLink = page.locator('a[href*="page=list_perm_templ"]').first();
      await expect(permTemplatesLink).toBeVisible();
    });

    test('should have Logout link', async ({ page }) => {
      const logoutLink = page.locator('a[href*="page=logout"]').first();
      await expect(logoutLink).toBeVisible();
    });

    test('should navigate to search page', async ({ page }) => {
      await page.locator('a[href*="page=search"]').first().click();
      await expect(page).toHaveURL(/page=search/);
    });

    test('should navigate to list zones page', async ({ page }) => {
      await page.locator('a[href*="page=list_zones"]').first().click();
      await expect(page).toHaveURL(/page=list_zones/);
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
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should not have Zone logs link (not admin)', async ({ page }) => {
      const zoneLogsLink = page.locator('a[href*="page=list_log_zones"]');
      expect(await zoneLogsLink.count()).toBe(0);
    });

    test('should not have Permission templates link', async ({ page }) => {
      const permTemplatesLink = page.locator('a[href*="page=list_perm_templ"]');
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

    test('should have Search link', async ({ page }) => {
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should not have Add master zone link', async ({ page }) => {
      const addMasterLink = page.locator('a[href*="page=add_zone_master"]');
      expect(await addMasterLink.count()).toBe(0);
    });

    test('should not have Add slave zone link', async ({ page }) => {
      const addSlaveLink = page.locator('a[href*="page=add_zone_slave"]');
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
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should have List zones link (view permission)', async ({ page }) => {
      const listZonesLink = page.locator('a[href*="page=list_zones"]').first();
      await expect(listZonesLink).toBeVisible();
    });

    test('should not have Add master zone link', async ({ page }) => {
      const addMasterLink = page.locator('a[href*="page=add_zone_master"]');
      expect(await addMasterLink.count()).toBe(0);
    });
  });
});
