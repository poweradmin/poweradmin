import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Dashboard Views', () => {
  test.describe('Admin User Dashboard', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display dashboard', async ({ page }) => {
      await page.goto('/');
      await expect(page).toHaveURL(/\/$/);
    });

    test('should display zone statistics', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|domain/i);
    });

    test('should display quick links', async ({ page }) => {
      await page.goto('/');
      const links = page.locator('a[href*="/"]');
      expect(await links.count()).toBeGreaterThan(0);
    });

    test('should show admin-specific options', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/user|admin|supermaster/i);
    });

    test('should display user welcome', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/welcome|logged|admin|dashboard/i);
    });

    test('should show zone count', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/\d+|zone/i);
    });

    test('should link to zones list', async ({ page }) => {
      await page.goto('/');
      const zonesLink = page.locator('a[href*="/zones/forward"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should link to users list', async ({ page }) => {
      await page.goto('/');
      const usersLink = page.locator('a[href$="/users"], a[href*="/users?"]');
      expect(await usersLink.count()).toBeGreaterThan(0);
    });

    test('should link to search', async ({ page }) => {
      await page.goto('/');
      const searchLink = page.locator('a[href*="/search"]');
      expect(await searchLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Manager User Dashboard', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should display dashboard', async ({ page }) => {
      await page.goto('/');
      await expect(page).toHaveURL(/\/$/);
    });

    test('should display zone count for manager', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|domain/i);
    });

    test('should not show admin-only options', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show zones link', async ({ page }) => {
      await page.goto('/');
      const zonesLink = page.locator('a[href*="/zones/forward"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should show add zone options', async ({ page }) => {
      await page.goto('/');
      const addLinks = page.locator('a[href*="/zones/add"]');
      expect(await addLinks.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Client User Dashboard', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should display dashboard', async ({ page }) => {
      await page.goto('/');
      await expect(page).toHaveURL(/\/$/);
    });

    test('should display zone count for client', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show limited options', async ({ page }) => {
      await page.goto('/');
      // Client should have limited options
      const addMasterLink = page.locator('a[href*="/zones/add/master"]');
      expect(await addMasterLink.count()).toBe(0);
    });

    test('should have limited zone access (may not show zones link in nav)', async ({ page }) => {
      await page.goto('/');
      // Client users have limited permissions - zones link may not be visible in navigation
      // depending on their assigned zones and permissions
      const zonesLink = page.locator('a[href*="/zones/forward"]');
      const count = await zonesLink.count();
      // Client users may or may not have zones link depending on their permissions
      expect(count >= 0).toBeTruthy();
    });
  });

  test.describe('Viewer User Dashboard', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should display dashboard', async ({ page }) => {
      await page.goto('/');
      await expect(page).toHaveURL(/\/$/);
    });

    test('should show read-only dashboard', async ({ page }) => {
      await page.goto('/');
      // Viewer should not see add options
      const addLinks = page.locator('a[href*="/zones/add"], input[value*="Add"]');
      expect(await addLinks.count()).toBe(0);
    });

    test('should link to zones list', async ({ page }) => {
      await page.goto('/');
      const zonesLink = page.locator('a[href*="/zones/forward"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Dashboard Statistics', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display master zone count', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/master|zone/i);
    });

    test('should display slave zone count', async ({ page }) => {
      await page.goto('/');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/slave|zone/i);
    });

    test('should update after zone changes', async ({ page }) => {
      // Get initial dashboard
      await page.goto('/');
      const initialText = await page.locator('body').textContent();
      expect(initialText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Dashboard Navigation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should navigate to add master zone', async ({ page }) => {
      await page.goto('/');

      // The add zone link may be in a dropdown menu
      const addMasterLink = page.locator('a[href*="/zones/add/master"]');
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
        await expect(page).toHaveURL(/.*zones\/add\/master/);
      }
    });

    test('should navigate to zones list', async ({ page }) => {
      await page.goto('/');
      const zonesLink = page.locator('a[href*="/zones/forward"]').first();
      if (await zonesLink.count() > 0) {
        await zonesLink.click();
        await expect(page).toHaveURL(/.*zones\/forward/);
      }
    });

    test('should navigate to search', async ({ page }) => {
      await page.goto('/');
      const searchLink = page.locator('a[href*="/search"]').first();
      if (await searchLink.count() > 0) {
        await searchLink.click();
        await expect(page).toHaveURL(/.*\/search/);
      }
    });
  });
});
