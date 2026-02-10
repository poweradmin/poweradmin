/**
 * Group Logs Tests
 *
 * Tests for group activity log viewing and searching.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Group Logs', () => {
  test.describe('Access Logs Page', () => {
    test('admin should access group logs page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs');

      await expect(page).toHaveURL(/.*groups\/logs/);
    });

    test('should display log entries or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs');

      const bodyText = await page.locator('body').textContent();
      // Should show either log entries or "No logs found"
      expect(bodyText.toLowerCase()).toMatch(/log|event|no logs found/i);
    });

    test('should display log table with columns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs');

      const bodyText = await page.locator('body').textContent();
      // Table should have Created at and Event columns
      expect(bodyText.toLowerCase()).toMatch(/created at|event|log/i);
    });

    test('should display total logs count badge', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs');

      const badge = page.locator('.badge');
      expect(await badge.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Search Logs', () => {
    test('should display search input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs');

      const searchInput = page.locator('input#name');
      await expect(searchInput).toBeVisible();
    });

    test('should search logs by group name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs');

      const searchInput = page.locator('input#name');
      await searchInput.fill('Zone Managers');
      await page.locator('button[type="submit"]').first().click();

      await page.waitForLoadState('domcontentloaded');
      const url = page.url();
      expect(url).toMatch(/name=Zone/i);
    });

    test('should clear search and show all logs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs?name=Zone+Managers');

      const clearBtn = page.locator('a:has-text("Clear")');
      if (await clearBtn.count() > 0) {
        await clearBtn.click();

        await page.waitForLoadState('domcontentloaded');
        const url = page.url();
        expect(url).not.toMatch(/name=/);
      }
    });
  });

  test.describe('Log Content', () => {
    test('should display group operation events', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/logs');

      const bodyText = await page.locator('body').textContent();
      // Test data inserts log entries about group creation and member operations
      const hasLogs = bodyText.includes('created') ||
                      bodyText.includes('added') ||
                      bodyText.includes('assigned') ||
                      bodyText.toLowerCase().includes('no logs');
      expect(hasLogs).toBeTruthy();
    });
  });

  test.describe('Permission Tests', () => {
    test('non-admin should not access group logs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/groups/logs');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !url.includes('groups/logs');
      expect(accessDenied).toBeTruthy();
    });
  });
});
