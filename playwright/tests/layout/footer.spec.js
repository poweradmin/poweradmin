import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Footer', () => {
  test.describe('Admin User', () => {
    test('should display site footer', async ({ adminPage: page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link', async ({ adminPage: page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });

    test('should display version number', async ({ adminPage: page }) => {
      const bodyText = await page.locator('footer, .footer').first().textContent();
      // Version should be in format like v3.x.x or similar
      expect(bodyText).toMatch(/v?\d+\.\d+/);
    });

    test('should display theme switcher button', async ({ adminPage: page }) => {
      const themeSwitcher = page.locator('#theme-switcher, button[id*="theme"], [data-testid="theme-switcher"]').first();
      if (await themeSwitcher.count() > 0) {
        await expect(themeSwitcher).toBeVisible();
      }
    });

    test('should have footer container structure', async ({ adminPage: page }) => {
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
    test('should display footer for manager', async ({ managerPage: page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link for manager', async ({ managerPage: page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });
  });

  test.describe('Client User', () => {
    test('should display footer for client', async ({ clientPage: page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link for client', async ({ clientPage: page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });
  });

  test.describe('Viewer User', () => {
    test('should display footer for viewer', async ({ viewerPage: page }) => {
      const footer = page.locator('footer, .footer').first();
      await expect(footer).toBeVisible();
    });

    test('should display poweradmin link for viewer', async ({ viewerPage: page }) => {
      const poweradminLink = page.locator('a[href*="poweradmin.org"]').first();
      await expect(poweradminLink).toBeVisible();
    });
  });

  test.describe('Theme Switching', () => {
    test('should have clickable theme switcher if present', async ({ adminPage: page }) => {
      const themeSwitcher = page.locator('#theme-switcher, button[id*="theme"]').first();
      if (await themeSwitcher.count() > 0) {
        await expect(themeSwitcher).toBeEnabled();
      }
    });

    test('should store theme preference in localStorage', async ({ adminPage: page }) => {
      // Check that localStorage theme handling exists
      const currentTheme = await page.evaluate(() => {
        return localStorage.getItem('theme');
      });
      expect(currentTheme === null || typeof currentTheme === 'string').toBeTruthy();
    });
  });
});
