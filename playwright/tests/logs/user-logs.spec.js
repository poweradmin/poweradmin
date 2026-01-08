import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('User Logs', () => {
  test.describe('Admin User', () => {
    test('should display user logs page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      await expect(page).toHaveURL(/page=list_log_users/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/user|log/i);
    });

    test('should display search form', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const form = page.locator('form').first();
      await expect(form).toBeVisible();
    });

    test('should display search input', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const searchInput = page.locator('input[type="text"], input[type="search"], input[name*="search"], input[name*="name"]').first();
      await expect(searchInput).toBeVisible();
    });

    test('should display search button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const searchBtn = page.locator('button:has-text("Search"), input[value="Search"]').first();
      await expect(searchBtn).toBeVisible();
    });

    test('should display logs table or no logs message', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const table = page.locator('table').first();
      const noLogsMsg = page.locator('body');
      if (await table.count() > 0) {
        await expect(table).toBeVisible();
      } else {
        const bodyText = await noLogsMsg.textContent();
        expect(bodyText.toLowerCase()).toMatch(/no.*log|empty/i);
      }
    });

    test('should allow typing in search input', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const searchInput = page.locator('input[type="text"], input[name*="search"], input[name*="name"]').first();
      await searchInput.fill('admin');
      await expect(searchInput).toHaveValue('admin');
    });

    test('should submit search form', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const searchInput = page.locator('input[type="text"], input[name*="search"], input[name*="name"]').first();
      await searchInput.fill('test');
      const searchBtn = page.locator('button:has-text("Search"), input[value="Search"]').first();
      await searchBtn.click();
      await expect(page).toHaveURL(/list_log_users/);
    });

    test('should display clear button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const clearBtn = page.locator('a:has-text("Clear"), button:has-text("Clear")').first();
      if (await clearBtn.count() > 0) {
        await expect(clearBtn).toBeVisible();
      }
    });

    test('should display log entries when available', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const rows = page.locator('table tbody tr');
      if (await rows.count() > 0) {
        await expect(rows.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User - Permission Check', () => {
    test('should not have access to user logs', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      // Should be redirected or show error
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('page=index');
      expect(hasError || !page.url().includes('list_log_users')).toBeTruthy();
    });
  });

  test.describe('Client User - Permission Check', () => {
    test('should not have access to user logs', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('page=index');
      expect(hasError || !page.url().includes('list_log_users')).toBeTruthy();
    });
  });

  test.describe('Viewer User - Permission Check', () => {
    test('should not have access to user logs', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('page=index');
      expect(hasError || !page.url().includes('list_log_users')).toBeTruthy();
    });
  });
});
