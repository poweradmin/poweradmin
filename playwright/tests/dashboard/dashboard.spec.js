import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Dashboard', () => {
  test.describe('Admin User', () => {
    test('should display welcome heading', async ({ adminPage: page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should display dashboard content', async ({ adminPage: page }) => {
      // Dashboard should have some content area
      const mainContent = page.locator('main, .container, .content, #content').first();
      await expect(mainContent).toBeVisible();
    });

    test('should have Search link', async ({ adminPage: page }) => {
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should have List zones link', async ({ adminPage: page }) => {
      const listZonesLink = page.locator('a[href*="page=list_zones"]').first();
      await expect(listZonesLink).toBeVisible();
    });

    test('should have Zone templates link', async ({ adminPage: page }) => {
      const templatesLink = page.locator('a[href*="page=list_zone_templ"]').first();
      await expect(templatesLink).toBeVisible();
    });

    test('should have Supermasters link', async ({ adminPage: page }) => {
      const supermastersLink = page.locator('a[href*="page=list_supermasters"]').first();
      await expect(supermastersLink).toBeVisible();
    });

    test('should have Add master zone link', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const addMasterLink = page.locator('a[href*="page=add_zone_master"]');
      expect(await addMasterLink.count()).toBeGreaterThan(0);
    });

    test('should have Add slave zone link', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const addSlaveLink = page.locator('a[href*="page=add_zone_slave"]');
      expect(await addSlaveLink.count()).toBeGreaterThan(0);
    });

    test('should have Add supermaster link', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const addSupermasterLink = page.locator('a[href*="page=add_supermaster"]');
      expect(await addSupermasterLink.count()).toBeGreaterThan(0);
    });

    test('should have Bulk registration link', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const bulkLink = page.locator('a[href*="page=bulk_registration"]');
      expect(await bulkLink.count()).toBeGreaterThan(0);
    });

    test('should have Zone logs link for admin', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const zoneLogsLink = page.locator('a[href*="page=list_log_zones"]');
      expect(await zoneLogsLink.count()).toBeGreaterThan(0);
    });

    test('should have Change password link', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const changePasswordLink = page.locator('a[href*="page=change_password"]');
      expect(await changePasswordLink.count()).toBeGreaterThan(0);
    });

    test('should have User administration link', async ({ adminPage: page }) => {
      const userAdminLink = page.locator('a[href*="page=users"]').first();
      await expect(userAdminLink).toBeVisible();
    });

    test('should have Permission templates link', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const permTemplatesLink = page.locator('a[href*="page=list_perm_templ"]');
      expect(await permTemplatesLink.count()).toBeGreaterThan(0);
    });

    test('should have Logout link', async ({ adminPage: page }) => {
      // Link may be in dropdown menu, check if it exists in DOM
      const logoutLink = page.locator('a[href*="page=logout"]');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });

    test('should navigate to search page', async ({ adminPage: page }) => {
      await page.locator('a[href*="page=search"]').first().click();
      await expect(page).toHaveURL(/page=search/);
    });

    test('should navigate to list zones page', async ({ adminPage: page }) => {
      await page.locator('a[href*="page=list_zones"]').first().click();
      await expect(page).toHaveURL(/page=list_zones/);
    });
  });

  test.describe('Manager User', () => {
    test('should display welcome heading', async ({ managerPage: page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should have Search link', async ({ managerPage: page }) => {
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should not have Zone logs link (not admin)', async ({ managerPage: page }) => {
      const zoneLogsLink = page.locator('a[href*="page=list_log_zones"]');
      expect(await zoneLogsLink.count()).toBe(0);
    });

    test('should not have Permission templates link', async ({ managerPage: page }) => {
      const permTemplatesLink = page.locator('a[href*="page=list_perm_templ"]');
      expect(await permTemplatesLink.count()).toBe(0);
    });
  });

  test.describe('Client User', () => {
    test('should display welcome heading', async ({ clientPage: page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should have Search link', async ({ clientPage: page }) => {
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should not have Add master zone link', async ({ clientPage: page }) => {
      const addMasterLink = page.locator('a[href*="page=add_zone_master"]');
      expect(await addMasterLink.count()).toBe(0);
    });

    test('should not have Add slave zone link', async ({ clientPage: page }) => {
      const addSlaveLink = page.locator('a[href*="page=add_zone_slave"]');
      expect(await addSlaveLink.count()).toBe(0);
    });
  });

  test.describe('Viewer User', () => {
    test('should display welcome heading', async ({ viewerPage: page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should have Search link', async ({ viewerPage: page }) => {
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should have List zones link (view permission)', async ({ viewerPage: page }) => {
      const listZonesLink = page.locator('a[href*="page=list_zones"]').first();
      await expect(listZonesLink).toBeVisible();
    });

    test('should not have Add master zone link', async ({ viewerPage: page }) => {
      const addMasterLink = page.locator('a[href*="page=add_zone_master"]');
      expect(await addMasterLink.count()).toBe(0);
    });
  });
});
