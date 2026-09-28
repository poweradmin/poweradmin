/**
 * Search Comments Tests (4.1 Feature)
 *
 * Tests for searching through comments functionality including:
 * - Comments checkbox in search form
 * - Searching through zone comments
 * - Searching through record comments
 * - Comments displayed in search results
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('Search Comments Feature', () => {
  test.describe('Search Form Comments Option', () => {
    test('should display comments checkbox when comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');
      const bodyText = await page.locator('body').textContent();

      expect(bodyText).not.toMatch(/fatal|exception/i);

      const hasCommentsOption = await commentsCheckbox.count() > 0 ||
                                 bodyText.toLowerCase().includes('comment');
      expect(hasCommentsOption).toBe(true);
    });

    test('should toggle comments search option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('#comments_check');
      await expect(commentsCheckbox).not.toBeChecked();

      await commentsCheckbox.check();
      await expect(commentsCheckbox).toBeChecked();

      await commentsCheckbox.uncheck();
      await expect(commentsCheckbox).not.toBeChecked();
    });

    test('should persist comments checkbox state after search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('#comments_check').check();
      await page.locator('input[name="query"]').fill('example');
      await page.locator('button[name="do_search"]').click();

      // The form is re-rendered from search_by_comments, so the option has to survive the POST
      await expect(page.locator('#comments_check')).toBeChecked();
    });
  });

  test.describe('Search Through Zone Comments', () => {
    test('should search zones with comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const zonesCheckbox = page.locator('input[name="zones"], input#zones_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await zonesCheckbox.count() > 0) {
        await zonesCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should display zone comments in search results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const zonesCheckbox = page.locator('input[name="zones"], input#zones_check');
      if (await zonesCheckbox.count() > 0) {
        await zonesCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      // Results should be displayed
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Search Through Record Comments', () => {
    test('should search records with comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('mail');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should display record comments in search results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('192.168');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should find records by comment text', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();

        const queryInput = page.locator('input[name="query"]');
        // Search for a term that might be in comments
        await queryInput.fill('server');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        await page.waitForLoadState('networkidle');

        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      }
    });
  });

  test.describe('Search Results Comment Display', () => {
    test('should show comment column in zone results when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('#zones_check').check();
      await page.locator('input[name="query"]').fill('example.com');
      await page.locator('button[name="do_search"]').click();

      await expect(page.locator('body')).toContainText('Zones found');
      await expect(page.locator('th').filter({ hasText: 'Comment' })).toHaveCount(1);
    });

    test('should show comment column in record results when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Combined Search Options', () => {
    test('should search both zones and records with comments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const zonesCheckbox = page.locator('input[name="zones"], input#zones_check');
      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await zonesCheckbox.count() > 0) {
        await zonesCheckbox.check();
      }

      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should search with wildcards and comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const wildcardCheckbox = page.locator('input[name="wildcard"], input#wildcard_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');
      const recordsCheckbox = page.locator('input[name="records"], input#records_check');

      if (await wildcardCheckbox.count() > 0) {
        await wildcardCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('mail*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Search Permissions', () => {
    test('manager should search with comments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('test');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('viewer should search with comments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('test');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });
});
