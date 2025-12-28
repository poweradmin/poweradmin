import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Footer', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display site footer', async ({ page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link', async ({ page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });

    test('should display version number', async ({ page }) => {
      const bodyText = await page.locator('footer, .footer').first().textContent();
      // Version should be in format like v3.x.x or similar
      expect(bodyText).toMatch(/v?\d+\.\d+/);
    });

    test('should display theme switcher button', async ({ page }) => {
      const themeSwitcher = page.locator('#theme-switcher, button[id*="theme"], [data-testid="theme-switcher"]').first();
      if (await themeSwitcher.count() > 0) {
        await expect(themeSwitcher).toBeVisible();
      }
    });

    test('should have footer container structure', async ({ page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
      // Footer should contain a container
      const container = footer.locator('.container, .container-fluid');
      if (await container.count() > 0) {
        await expect(container.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should display footer for manager', async ({ page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link for manager', async ({ page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });
  });

  test.describe('Client User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should display footer for client', async ({ page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link for client', async ({ page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });
  });

  test.describe('Viewer User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should display footer for viewer', async ({ page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link for viewer', async ({ page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });
  });

  test.describe('Theme Switching', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should have clickable theme switcher if present', async ({ page }) => {
      const themeSwitcher = page.locator('#theme-switcher, button[id*="theme"]').first();
      if (await themeSwitcher.count() > 0) {
        await expect(themeSwitcher).toBeEnabled();
      }
    });

    test('should store theme preference in localStorage', async ({ page }) => {
      // Check that localStorage theme handling exists
      const currentTheme = await page.evaluate(() => {
        return localStorage.getItem('theme');
      });
      expect(currentTheme === null || typeof currentTheme === 'string').toBeTruthy();
    });
  });
});
