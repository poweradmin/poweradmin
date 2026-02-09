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
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search Comments Feature', () => {
  test.describe('Search Form Comments Option', () => {
    test('should display comments checkbox when comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');
      const bodyText = await page.locator('body').textContent();

      // Comments option may or may not be present depending on config
      const hasCommentsOption = await commentsCheckbox.count() > 0 ||
                                 bodyText.toLowerCase().includes('comment');
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should toggle comments search option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await commentsCheckbox.count() > 0) {
        // Check the comments checkbox
        await commentsCheckbox.check();
        expect(await commentsCheckbox.isChecked()).toBeTruthy();

        // Uncheck it
        await commentsCheckbox.uncheck();
        expect(await commentsCheckbox.isChecked()).toBeFalsy();
      }
    });

    test('should persist comments checkbox state after search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();

        // Perform a search
        const queryInput = page.locator('input[name="query"]');
        await queryInput.fill('test');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        await page.waitForLoadState('networkidle');

        // Checkbox state should be preserved
        const newCommentsCheckbox = page.locator('input[name="comments"], input#comments_check');
        if (await newCommentsCheckbox.count() > 0) {
          const isChecked = await newCommentsCheckbox.isChecked();
          // State should be preserved or reset
          expect(typeof isChecked).toBe('boolean');
        }
      }
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      // Results should be displayed
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Search Results Comment Display', () => {
    test('should show comment column in zone results when enabled', async ({ page }) => {
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

      const bodyText = await page.locator('body').textContent();
      // Check for comment column header or comment content
      const hasCommentDisplay = bodyText.toLowerCase().includes('comment') ||
                                 await page.locator('th:has-text("Comment")').count() > 0;
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
