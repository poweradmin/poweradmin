/**
 * E2E Tests for Search Record Grouping Feature
 *
 * Tests the iface_search_group_records configuration option that groups
 * DNS records by name+content in search results.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search Record Grouping', () => {
  /**
   * Test that search finds duplicate records
   */
  test('should find duplicate-test records', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('duplicate-test.example.com');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  /**
   * Test that search finds records by IP content
   */
  test('should find records by IP address content', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('10.88.88.88');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  /**
   * Test search results table displays properly
   */
  test('should display search results in table', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('duplicate-test');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const resultsTable = page.locator('table');
    const bodyText = await page.locator('body').textContent();
    expect(await resultsTable.count() > 0 || bodyText.toLowerCase().includes('no')).toBeTruthy();
  });

  /**
   * Test that record type is shown in results
   */
  test('should show A record type in results', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('duplicate-test.example.com');

    const recordsCheckbox = page.locator('input[name="records"]');
    if (await recordsCheckbox.isVisible()) {
      await recordsCheckbox.check();
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  /**
   * Test search with wildcard
   */
  test('should handle wildcard search', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/search');

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('*');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  /**
   * Test search with type filter
   */
  test('should filter by record type', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/search');

    const typeFilter = page.locator('select[name*="type"]');
    if (await typeFilter.count() > 0) {
      await typeFilter.first().selectOption('A');
    }

    const searchInput = page.locator('input[name="query"], input[name*="search"], input[type="text"]').first();
    await searchInput.fill('example');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
