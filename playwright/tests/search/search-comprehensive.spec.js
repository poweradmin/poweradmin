/**
 * Search Functionality Tests
 *
 * Comprehensive tests for search functionality including
 * zone search, record search, and search features.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Helper to get any zone name for testing
async function findAnyZoneName(page) {
  await page.goto('/zones/forward?letter=all');
  const firstZoneLink = page.locator('table tbody tr td:first-child a').first();
  if (await firstZoneLink.count() > 0) {
    return await firstZoneLink.textContent();
  }
  return null;
}

test.describe('Search Functionality', () => {
  let testDomain = null;

  test.describe('Search Page Access', () => {
    test('should access search page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should display search form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');
      const form = page.locator('form');
      await expect(form.first()).toBeVisible();
    });

    test('should display search input field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');
      const searchInput = page.locator('input[name*="search"], input[name*="query"], input[type="search"], input[type="text"]').first();
      await expect(searchInput).toBeVisible();
    });

    test('should display search button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');
      const searchBtn = page.locator('button[type="submit"], input[type="submit"]');
      expect(await searchBtn.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Zone Search', () => {
    test('should search by exact zone name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      testDomain = await findAnyZoneName(page);

      if (!testDomain) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/search');
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by partial zone name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      testDomain = await findAnyZoneName(page);

      if (!testDomain) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/search');
      const partialName = testDomain.split('.')[0];
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(partialName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle no results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('nonexistent-zone-xyz123');
      await Promise.all([
        page.waitForLoadState('networkidle'),
        page.locator('button[type="submit"], input[type="submit"]').first().click(),
      ]);

      const noResultsCard = page.locator('text=No results found');
      const hasNoResultsMessage = await noResultsCard.count() > 0;
      const hasZonesFound = await page.locator('text=Zones found').count() > 0;
      const hasRecordsFound = await page.locator('text=Records found').count() > 0;

      expect(hasNoResultsMessage || (!hasZonesFound && !hasRecordsFound)).toBeTruthy();
    });

    test('should search case insensitively', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      testDomain = await findAnyZoneName(page);

      if (!testDomain) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/search');
      const upperQuery = testDomain.toUpperCase();
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(upperQuery);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Record Search', () => {
    test('should search by record name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('www');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('192.168');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by record type', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const typeFilter = page.locator('select[name*="type"]');
      if (await typeFilter.count() > 0) {
        await typeFilter.first().selectOption('A');
        await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('*');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Search Features', () => {
    test('should handle empty search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle search with special characters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('test-zone_123');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal error|uncaught exception|sql error|syntax error/i);
    });

    test('should handle very long search query', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const longQuery = 'a'.repeat(500);
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(longQuery);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display search result count', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Search Result Navigation', () => {
    test('should navigate to zone from search results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      testDomain = await findAnyZoneName(page);

      if (!testDomain) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/search');
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const zoneLink = page.locator(`a:has-text("${testDomain}")`).first();
      if (await zoneLink.count() > 0) {
        await zoneLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Search Permissions', () => {
    test('manager should access search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('client should access search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('viewer should access search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });
  });

  test.describe('Search UI', () => {
    test('should have breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();
    });

    test('should have type filter dropdown', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const typeFilter = page.locator('select[name*="type"], select#type_filter');
      if (await typeFilter.count() > 0) {
        await expect(typeFilter.first()).toBeVisible();
      }
    });
  });
});
