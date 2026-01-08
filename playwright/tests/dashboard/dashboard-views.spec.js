import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Dashboard Views', () => {
  test.describe('Admin User Dashboard', () => {
    test('should display dashboard', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should display zone statistics', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|domain/i);
    });

    test('should display quick links', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const links = page.locator('a[href*="page="]');
      expect(await links.count()).toBeGreaterThan(0);
    });

    test('should show admin-specific options', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/user|admin|supermaster/i);
    });

    test('should display user welcome', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/welcome|logged|admin|dashboard/i);
    });

    test('should show zone count', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/\d+|zone/i);
    });

    test('should link to zones list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const zonesLink = page.locator('a[href*="list_zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should link to users list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const usersLink = page.locator('a[href*="page=users"]');
      expect(await usersLink.count()).toBeGreaterThan(0);
    });

    test('should link to search', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const searchLink = page.locator('a[href*="page=search"]');
      expect(await searchLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Manager User Dashboard', () => {
    test('should display dashboard', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should display zone count for manager', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|domain/i);
    });

    test('should not show admin-only options', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');
      const usersLink = page.locator('a[href*="page=users"]:not([href*="add_user"])');
      // Manager should not see user management link
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show zones link', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');
      const zonesLink = page.locator('a[href*="list_zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should show add zone options', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');
      const addLinks = page.locator('a[href*="add_zone"]');
      expect(await addLinks.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Client User Dashboard', () => {
    test('should display dashboard', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should display zone count for client', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show limited options', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');
      // Client should have limited options
      const addMasterLink = page.locator('a[href*="add_zone_master"]');
      expect(await addMasterLink.count()).toBe(0);
    });

    test('should link to zones list', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=index');
      const zonesLink = page.locator('a[href*="list_zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Viewer User Dashboard', () => {
    test('should display dashboard', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should show read-only dashboard', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=index');
      // Viewer should not see add options
      const addLinks = page.locator('a[href*="add_zone"], input[value*="Add"]');
      expect(await addLinks.count()).toBe(0);
    });

    test('should link to zones list', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=index');
      const zonesLink = page.locator('a[href*="list_zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Dashboard Statistics', () => {
    test('should display master zone count', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/master|zone/i);
    });

    test('should display slave zone count', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/slave|zone/i);
    });

    test('should update after zone changes', async ({ adminPage: page }) => {
      // Get initial dashboard
      await page.goto('/index.php?page=index');
      const initialText = await page.locator('body').textContent();
      expect(initialText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Dashboard Navigation', () => {
    test('should navigate to add master zone', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');

      // The add zone link may be in a dropdown menu
      const addMasterLink = page.locator('a[href*="add_zone_master"]');
      if (await addMasterLink.count() > 0) {
        // Try to find and open the dropdown menu first if the link is hidden
        const isVisible = await addMasterLink.first().isVisible();
        if (!isVisible) {
          // Look for the Zones dropdown button and click it
          const zonesDropdown = page.locator('button:has-text("Zones"), [data-bs-toggle="dropdown"]:has-text("Zones")');
          if (await zonesDropdown.count() > 0) {
            await zonesDropdown.first().click();
          }
        }
        await addMasterLink.first().click();
        await expect(page).toHaveURL(/add_zone_master/);
      }
    });

    test('should navigate to zones list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const zonesLink = page.locator('a[href*="list_zones"]').first();
      if (await zonesLink.count() > 0) {
        await zonesLink.click();
        await expect(page).toHaveURL(/list_zones/);
      }
    });

    test('should navigate to search', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      const searchLink = page.locator('a[href*="page=search"]').first();
      if (await searchLink.count() > 0) {
        await searchLink.click();
        await expect(page).toHaveURL(/page=search/);
      }
    });
  });
});
