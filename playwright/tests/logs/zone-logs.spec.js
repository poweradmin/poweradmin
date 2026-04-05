import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Logs', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display zone logs page', async ({ page }) => {
      await page.goto('/zones/logs');
      await expect(page).toHaveURL(/.*zones\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|log/i);
    });

    test('should display search form', async ({ page }) => {
      await page.goto('/zones/logs');
      const form = page.locator('form').first();
      await expect(form).toBeVisible();
    });

    test('should display search input', async ({ page }) => {
      await page.goto('/zones/logs');
      const searchInput = page.locator('input[type="text"], input[type="search"], input[name*="search"], input[name*="name"]').first();
      await expect(searchInput).toBeVisible();
    });

    test('should display search button', async ({ page }) => {
      await page.goto('/zones/logs');
      const searchBtn = page.locator('button[type="submit"]').first();
      await expect(searchBtn).toBeVisible();
    });

    test('should display logs table or no logs message', async ({ page }) => {
      await page.goto('/zones/logs');
      const table = page.locator('table').first();
      const noLogsMsg = page.locator('body');
      if (await table.count() > 0) {
        await expect(table).toBeVisible();
      } else {
        const bodyText = await noLogsMsg.textContent();
        expect(bodyText.toLowerCase()).toMatch(/no.*log|empty/i);
      }
    });

    test('should display logs count', async ({ page }) => {
      await page.goto('/zones/logs');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/total|log|count/i);
    });

    test('should allow typing in search input', async ({ page }) => {
      await page.goto('/zones/logs');
      const searchInput = page.locator('input[type="text"], input[name*="search"], input[name*="name"]').first();
      await searchInput.fill('example.com');
      await expect(searchInput).toHaveValue('example.com');
    });

    test('should submit search form', async ({ page }) => {
      await page.goto('/zones/logs');
      const searchInput = page.locator('input[type="text"], input[name*="search"], input[name*="name"]').first();
      await searchInput.fill('test');
      const searchBtn = page.locator('button[type="submit"]').first();
      await searchBtn.click();
      await expect(page).toHaveURL(/zones\/logs/);
    });

    test('should display clear button', async ({ page }) => {
      await page.goto('/zones/logs');
      const clearBtn = page.locator('a:has-text("Clear"), button:has-text("Clear")').first();
      if (await clearBtn.count() > 0) {
        await expect(clearBtn).toBeVisible();
      }
    });

    test('should display log entries when available', async ({ page }) => {
      await page.goto('/zones/logs');
      const rows = page.locator('table tbody tr');
      if (await rows.count() > 0) {
        await expect(rows.first()).toBeVisible();
      }
    });

    test('should display details button when logs exist', async ({ page }) => {
      await page.goto('/zones/logs');
      const detailsBtn = page.locator('button[data-bs-toggle="modal"]');
      if (await detailsBtn.count() > 0) {
        await expect(detailsBtn.first()).toBeVisible();
      }
    });

    test('should display total logs count in header', async ({ page }) => {
      await page.goto('/zones/logs');
      const header = page.locator('.card-header');
      const headerText = await header.first().textContent();
      expect(headerText).toMatch(/Total logs/i);
    });

    test('should have operation filter dropdown', async ({ page }) => {
      await page.goto('/zones/logs');
      const operationSelect = page.locator('select[name="operation"]');
      await expect(operationSelect).toBeVisible();
    });

    test('should have user filter dropdown', async ({ page }) => {
      await page.goto('/zones/logs');
      const userSelect = page.locator('select[name="user"]');
      await expect(userSelect).toBeVisible();
    });

    test('should have date range filters', async ({ page }) => {
      await page.goto('/zones/logs');
      const dateFrom = page.locator('input[name="date_from"]');
      const dateTo = page.locator('input[name="date_to"]');
      await expect(dateFrom).toBeVisible();
      await expect(dateTo).toBeVisible();
    });

    test('should filter by operation', async ({ page }) => {
      await page.goto('/zones/logs');
      const operationSelect = page.locator('select[name="operation"]');
      await operationSelect.selectOption('add_zone');
      await page.locator('button[type="submit"]').first().click();
      await expect(page).toHaveURL(/operation=add_zone/);
    });

    test('should display color-coded operation badges', async ({ page }) => {
      await page.goto('/zones/logs');
      const badges = page.locator('.log-event-cell .badge');
      if (await badges.count() > 0) {
        await expect(badges.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should not have access to zone logs', async ({ page }) => {
      await page.goto('/zones/logs');
      // Should be redirected or show error
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('zones/logs')).toBeTruthy();
    });
  });

  test.describe('Client User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should not have access to zone logs', async ({ page }) => {
      await page.goto('/zones/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('zones/logs')).toBeTruthy();
    });
  });

  test.describe('Viewer User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should not have access to zone logs', async ({ page }) => {
      await page.goto('/zones/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('zones/logs')).toBeTruthy();
    });
  });
});
