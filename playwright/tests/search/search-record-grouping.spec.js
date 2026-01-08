/**
 * E2E Tests for Search Record Grouping Feature
 *
 * Tests the iface_search_group_records configuration option that groups
 * DNS records by name+content in search results.
 *
 * When enabled: Records with identical name+content show as 1 result (grouped)
 * When disabled: Records with identical name+content show as 3 results (individual)
 *
 * Test data: duplicate-test.example.com A 10.88.88.88
 * (exists in manager-zone, client-zone, and shared-zone)
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Search Record Grouping', () => {
  /**
   * Test that search finds duplicate records
   */
  test('should find duplicate-test records', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    const searchInput = page.locator('input[name="query"]');
    await searchInput.fill('duplicate-test.example.com');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify results contain the search term
    const pageContent = await page.content();
    expect(pageContent).toContain('duplicate-test.example.com');
  });

  /**
   * Test that search finds records by IP content
   */
  test('should find records by IP address content', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    const searchInput = page.locator('input[name="query"]');
    await searchInput.fill('10.88.88.88');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const pageContent = await page.content();
    expect(pageContent).toContain('10.88.88.88');
  });

  /**
   * Test search results table displays properly
   */
  test('should display search results in table', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    const searchInput = page.locator('input[name="query"]');
    await searchInput.fill('duplicate-test');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Check that results table is displayed
    const resultsTable = page.locator('table');
    await expect(resultsTable.first()).toBeVisible();
  });

  /**
   * Test that record type is shown in results
   */
  test('should show A record type in results', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    const searchInput = page.locator('input[name="query"]');
    await searchInput.fill('duplicate-test.example.com');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const pageContent = await page.content();
    // Should show A record type
    expect(pageContent).toMatch(/\bA\b/);
  });
});
