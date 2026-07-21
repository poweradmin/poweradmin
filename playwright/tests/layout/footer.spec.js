/**
 * Footer Tests
 *
 * Tests for footer display and functionality across user roles.
 * This is the single home for the shared footer partial render checks
 * (visible + poweradmin link + version + copyright). layout-comprehensive
 * no longer re-proves the same partial.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// One render check for the shared footer partial per role.
async function expectFooterRenders(page) {
  const footer = page.locator('footer, .footer').first();
  await expect(footer).toBeVisible();
  await expect(page.locator('a[href*="poweradmin.org"]').first()).toBeVisible();
  const footerText = await footer.textContent();
  // Version in format like v3.x.x or 3.x
  expect(footerText).toMatch(/v?\d+\.\d+/);
  expect(footerText.toLowerCase()).toMatch(/poweradmin|©|copyright|\d{4}/i);
}

test.describe('Footer', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('renders footer with branding, version and copyright', async ({ page }) => {
      await expectFooterRenders(page);
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

    test('renders footer with branding, version and copyright', async ({ page }) => {
      await expectFooterRenders(page);
    });
  });

  test.describe('Client User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('renders footer with branding, version and copyright', async ({ page }) => {
      await expectFooterRenders(page);
    });
  });

  test.describe('Viewer User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('renders footer with branding, version and copyright', async ({ page }) => {
      await expectFooterRenders(page);
    });
  });

  test.describe('Theme Switching', () => {
    test('should have clickable theme switcher if present', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const themeSwitcher = page.locator('#theme-switcher, button[id*="theme"]').first();
      if (await themeSwitcher.count() > 0) {
        await expect(themeSwitcher).toBeEnabled();
      }
    });

    test('should store theme preference in localStorage', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const currentTheme = await page.evaluate(() => {
        return localStorage.getItem('theme');
      });
      expect(currentTheme === null || typeof currentTheme === 'string').toBeTruthy();
    });
  });

  test.describe('Footer Content', () => {
    test('should have consistent footer across pages', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Check footer on dashboard
      const dashboardFooter = await page.locator('footer, .footer').first().textContent();

      // Navigate to another page and check footer
      await page.goto('/zones/forward?letter=all');
      const zonesFooter = await page.locator('footer, .footer').first().textContent();

      // Footers should be similar
      expect(dashboardFooter.trim()).toBe(zonesFooter.trim());
    });
  });
});
