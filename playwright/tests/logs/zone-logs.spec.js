import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { createZone, deleteZoneById } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

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
      expect(await table.count()).toBeGreaterThan(0);

      await expect(table).toBeVisible();
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
      // The control is icon-only, so it is found by its title rather than its text
      await expect(page.locator('a[title="Clear"]')).toBeVisible();
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
  });

  // The zone log is empty until a zone actually changes, so this block makes one
  // change of its own and asserts against that zone's entry.
  test.describe('Logged Zone Change', () => {
    const loggedZone = `zone-log-${Date.now()}.example.com`;
    let loggedZoneId = null;

    test.beforeAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      loggedZoneId = await createZone(page, loggedZone);
      await page.close();
    });

    test.afterAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      if (loggedZoneId) {
        await deleteZoneById(page, loggedZoneId);
      }
      await page.close();
    });

    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/logs?name=${loggedZone}`);
    });

    test('should display log entries for a changed zone', async ({ page }) => {
      await expect(page.locator('table tbody tr').first()).toBeVisible();
      await expect(page.locator('table')).toContainText(`zone:${loggedZone}`);
    });

    test('should display details button for a log entry', async ({ page }) => {
      await expect(page.locator('table tbody button[data-bs-toggle="modal"]').first()).toBeVisible();
    });

    test('should display color-coded operation badges', async ({ page }) => {
      const badge = page.locator('.log-event-cell .badge').filter({ hasText: 'add_zone' }).first();
      await expect(badge).toBeVisible();
      await expect(badge).toHaveClass(/bg-success/);
    });
  });

  test.describe('Manager User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should have access to zone logs (zone_logs_view_own)', async ({ page }) => {
      // Zone logs are permission-gated, no longer admin-only; the manager
      // fixture holds zone_logs_view_own so the page must load.
      await page.goto('/zones/logs');
      await expect(page).toHaveURL(/zones\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('denied');
    });
  });

  test.describe('Client User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should have access to zone logs (zone_logs_view_own)', async ({ page }) => {
      await page.goto('/zones/logs');
      await expect(page).toHaveURL(/zones\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('denied');
    });
  });

  test.describe('Viewer User - Permission Check', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should have access to zone logs (zone_logs_view_own)', async ({ page }) => {
      await page.goto('/zones/logs');
      await expect(page).toHaveURL(/zones\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('denied');
    });
  });
});
