/**
 * Search Functionality Tests
 *
 * Comprehensive tests for search functionality including
 * zone search, record search, and search features.
 */

import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };
import zones from '../../fixtures/zones.json' with { type: 'json' };

// Own zone, so the search tests do not depend on what the shared database holds
const zone = useFileZone('search');

test.describe('Search Functionality', () => {
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
      await page.goto('/search');
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(zone.name);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('body')).toContainText(zone.name);
    });

    test('should search by partial zone name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');
      const partialName = zone.name.split('.')[0];
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(partialName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('body')).toContainText(zone.name);
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
      await page.goto('/search');
      const upperQuery = zone.name.toUpperCase();
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(upperQuery);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('body')).toContainText(zone.name);
    });
  });

  test.describe('Record Search', () => {
    test('should search by record name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('www');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should search by record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('192.168');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should search by record type', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      // There is no type dropdown; the record type is given in the query as "type:a"
      await page.locator('input[name="query"]').fill('example type:a');
      await page.locator('button[name="do_search"]').click();

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      // SearchCriteria lifts the type out of the query and into the hidden field
      await expect(page.locator('#type_filter')).toHaveValue('A');
    });
  });

  test.describe('Search Features', () => {
    test('should handle empty search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should handle search with special characters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('test-zone_123');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal error|uncaught exception|sql error|syntax error/i);
    });

    test('should handle very long search query', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const longQuery = 'a'.repeat(500);
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(longQuery);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should display search result count', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Search Result Navigation', () => {
    test('should navigate to zone from search results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto('/search');
      await page.locator('input[name="query"]').fill(zones.admin.name);
      await page.locator('button[name="do_search"]').click();

      await expect(page.locator('body')).toContainText('Zones found');

      // The zone name is plain text in the results row, so the zone is reached
      // through its edit action rather than a link on the name
      const zoneRow = page.locator(`tr:has-text("${zones.admin.name}")`).first();
      await zoneRow.locator('a[href*="/zones/"][href*="/edit"]').first().click();

      await expect(page).toHaveURL(/\/zones\/[^/]+\/edit/);
      await expect(page.locator('body')).toContainText(zones.admin.name);
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

  });
});
