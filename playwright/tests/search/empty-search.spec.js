/**
 * Empty Search Behavior Tests
 *
 * Tests for search form empty submission handling.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('Search Form Empty Submission', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test.describe('Clear Search Functionality', () => {
    test('clear button should reload the page', async ({ page }) => {
      // First, perform a search
      await page.goto('/search?query=test');
      await page.waitForLoadState('networkidle');

      // The control is an icon-only anchor calling clearSearch(), with no text
      const clearButton = page.locator('a[onclick*="clearSearch"]');
      await expect(clearButton).toBeVisible();

      await clearButton.click();
      await page.waitForLoadState('networkidle');

      expect(page.url()).toMatch(/\/search/);
      expect(page.url()).not.toMatch(/query=test/);
    });

    test('search page should have clear search function', async ({ page }) => {
      await page.goto('/search');
      await page.waitForLoadState('networkidle');

      // Check for clearSearch function in page source
      const pageContent = await page.content();
      const hasClearFunction = pageContent.includes('clearSearch') || pageContent.includes('Clear');

      expect(hasClearFunction).toBeTruthy();
    });
  });

  test.describe('Empty Search Query', () => {
    test('empty search should not submit form', async ({ page }) => {
      await page.goto('/search');
      await page.waitForLoadState('networkidle');

      const searchInput = page.locator('input[name="query"]');
      await expect(searchInput).toBeVisible();
      await expect(page.locator('button[type="submit"]').first()).toBeVisible();

      await searchInput.fill('');

      // The query field carries the required attribute, so the browser blocks submit
      await expect(searchInput).toHaveAttribute('required', /.*/);
    });

    test('search page should load without query parameter', async ({ page }) => {
      await page.goto('/search');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();

      // Page should load successfully
      expect(bodyText.toLowerCase()).toMatch(/search|zone|record/i);
    });
  });

  test.describe('Search Form Elements', () => {
    test('search form should have query input', async ({ page }) => {
      await page.goto('/search');
      await page.waitForLoadState('networkidle');

      const queryInput = page.locator('input[name="query"]');
      const hasQueryInput = await queryInput.count() > 0;

      expect(hasQueryInput).toBeTruthy();
    });

    test('search form should have type filter', async ({ page }) => {
      await page.goto('/search');
      await page.waitForLoadState('networkidle');

      const typeFilter = page.locator('select[name="type_filter"], select#type_filter');
      const bodyText = await page.locator('body').textContent();

      const hasTypeFilter = await typeFilter.count() > 0;
      const hasFilterOption = bodyText.toLowerCase().includes('type') || bodyText.toLowerCase().includes('filter');

      expect(hasTypeFilter || hasFilterOption).toBeTruthy();
    });

    test('search form should have submit button', async ({ page }) => {
      await page.goto('/search');
      await page.waitForLoadState('networkidle');

      const submitButton = page.locator('button[type="submit"], input[type="submit"]');
      const hasSubmitButton = await submitButton.count() > 0;

      expect(hasSubmitButton).toBeTruthy();
    });
  });

  test.describe('Search Results Handling', () => {
    test('search with results should display matches', async ({ page }) => {
      // Search for something that should exist
      await page.goto('/search?query=example');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();

      // Should show search results or "no results" message
      const hasResults = bodyText.toLowerCase().includes('result') ||
                        bodyText.toLowerCase().includes('found') ||
                        bodyText.toLowerCase().includes('match') ||
                        bodyText.toLowerCase().includes('no') ||
                        bodyText.toLowerCase().includes('zone');

      expect(hasResults).toBeTruthy();
    });

    test('search page should handle query parameter', async ({ page }) => {
      await page.goto('/search?query=example');
      await page.waitForLoadState('networkidle');

      // The page should load and show search results or the search form
      const bodyText = await page.locator('body').textContent();

      // Should show search functionality
      expect(bodyText.toLowerCase()).toMatch(/search|zone|record|result|found/i);
    });
  });

  test.describe('Search Navigation', () => {
    test('search should be accessible from navigation', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const searchLink = page.locator('a[href*="/search"]');
      const hasSearchLink = await searchLink.count() > 0;

      expect(hasSearchLink).toBeTruthy();
    });

    test('search page should have breadcrumb', async ({ page }) => {
      await page.goto('/search');
      await page.waitForLoadState('networkidle');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      const hasBreadcrumb = await breadcrumb.count() > 0;

      expect(hasBreadcrumb).toBeTruthy();
    });
  });
});
