/**
 * E2E Tests for Search with Comments
 *
 * Regression test for #1105: SQL error and null type crash when
 * searching with comments checkbox enabled.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getZoneIdForTest } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Serial mode: the first test creates a record that later tests depend on
test.describe.configure({ mode: 'serial' });

test.describe('Search with Comments', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should create a record with a searchable comment', async ({ page }) => {
    const zoneId = await getZoneIdForTest(page);
    test.skip(!zoneId, 'No zone available');

    await page.goto(`/zones/${zoneId}/records/add`);

    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(`comment-test-${Date.now()}`);
    await page.locator('input[name*="content"]').first().fill('10.99.99.99');

    const commentField = page.locator('input[name*="comment"]').first();
    if (await commentField.isVisible()) {
      await commentField.fill('searchable comment');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  /**
   * Regression test for #1105: searching with comments checkbox should not
   * cause SQL errors from the EXISTS subquery on the comments table.
   */
  test('should search with comments enabled without SQL errors (#1105)', async ({ page }) => {
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('manager-zone');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    const commentsCheckbox = page.locator('input[name="comments"]');
    if (await commentsCheckbox.isVisible()) {
      await commentsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|error occurred|SQLSTATE/i);
  });

  /**
   * Test that searching for comment text returns matching records.
   */
  test('should find records by comment content', async ({ page }) => {
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('searchable comment');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    const commentsCheckbox = page.locator('input[name="comments"]');
    if (await commentsCheckbox.isVisible()) {
      await commentsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|error occurred|SQLSTATE/i);
    expect(bodyText).toContain('Records found');
  });

  /**
   * Test that searching with comments unchecked still works normally.
   */
  test('should search without comments checkbox without errors', async ({ page }) => {
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('example');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    const commentsCheckbox = page.locator('input[name="comments"]');
    if (await commentsCheckbox.isVisible()) {
      await commentsCheckbox.uncheck();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|error occurred|SQLSTATE/i);
  });
});
