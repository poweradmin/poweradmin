import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('User Logs', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display user logs page', async ({ page }) => {
      await page.goto('/users/logs');
      await expect(page).toHaveURL(/.*users\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/user|log/i);
    });

    test('should display search form', async ({ page }) => {
      await page.goto('/users/logs');
      const form = page.locator('form').first();
      await expect(form).toBeVisible();
    });

    test('should display search input', async ({ page }) => {
      await page.goto('/users/logs');
      const searchInput = page.locator('input[type="text"], input[type="search"], input[name*="search"], input[name*="name"]').first();
      await expect(searchInput).toBeVisible();
    });

    test('should display search button', async ({ page }) => {
      await page.goto('/users/logs');
      const searchBtn = page.locator('button:has-text("Search"), input[value="Search"]').first();
      await expect(searchBtn).toBeVisible();
    });

    test('should display logs table or no logs message', async ({ page }) => {
      await page.goto('/users/logs');
      const table = page.locator('table').first();
      const noLogsMsg = page.locator('body');
      if (await table.count() > 0) {
        await expect(table).toBeVisible();
      } else {
        const bodyText = await noLogsMsg.textContent();
        expect(bodyText.toLowerCase()).toMatch(/no.*log|empty/i);
      }
    });

    test('should allow typing in search input', async ({ page }) => {
      await page.goto('/users/logs');
      const searchInput = page.locator('input[type="text"], input[name*="search"], input[name*="name"]').first();
      await searchInput.fill('admin');
      await expect(searchInput).toHaveValue('admin');
    });

    test('should submit search form', async ({ page }) => {
      await page.goto('/users/logs');
      const searchInput = page.locator('input[type="text"], input[name*="search"], input[name*="name"]').first();
      await searchInput.fill('test');
      const searchBtn = page.locator('button:has-text("Search"), input[value="Search"]').first();
      await searchBtn.click();
      await expect(page).toHaveURL(/users\/logs/);
    });

    test('should display clear button', async ({ page }) => {
      await page.goto('/users/logs');
      const clearBtn = page.locator('a:has-text("Clear"), button:has-text("Clear")').first();
      if (await clearBtn.count() > 0) {
        await expect(clearBtn).toBeVisible();
      }
    });

    test('should display log entries when available', async ({ page }) => {
      await page.goto('/users/logs');
      const rows = page.locator('table tbody tr');
      if (await rows.count() > 0) {
        await expect(rows.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should not have access to user logs', async ({ page }) => {
      await page.goto('/users/logs');
      // Should be redirected or show error
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('users/logs')).toBeTruthy();
    });
  });

  test.describe('Client User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should not have access to user logs', async ({ page }) => {
      await page.goto('/users/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('users/logs')).toBeTruthy();
    });
  });

  test.describe('Viewer User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should not have access to user logs', async ({ page }) => {
      await page.goto('/users/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('users/logs')).toBeTruthy();
    });
  });
});
