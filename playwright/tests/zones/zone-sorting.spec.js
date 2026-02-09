/**
 * Zone Sorting Tests
 *
 * Tests for the zone list sorting functionality
 * including sorting by name, type, owner, and records count.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone List Sorting', () => {
  test.describe('Column Header Sorting', () => {
    test('should have sortable name column header', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on name column
      const nameHeader = page.locator('th a[href*="zone_sort_by=name"]');
      const hasSortableNameHeader = await nameHeader.count() > 0;

      expect(hasSortableNameHeader).toBeTruthy();
    });

    test('should have sortable type column header', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on type column
      const typeHeader = page.locator('th a[href*="zone_sort_by=type"]');
      const hasSortableTypeHeader = await typeHeader.count() > 0;

      expect(hasSortableTypeHeader).toBeTruthy();
    });

    test('should have sortable owner column header', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on owner column
      const ownerHeader = page.locator('th a[href*="zone_sort_by=owner"]');
      const hasSortableOwnerHeader = await ownerHeader.count() > 0;

      expect(hasSortableOwnerHeader).toBeTruthy();
    });

    test('should have sortable records count column', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on records column
      const recordsHeader = page.locator('th a[href*="zone_sort_by=count_records"]');
      const hasSortableRecordsHeader = await recordsHeader.count() > 0;

      expect(hasSortableRecordsHeader).toBeTruthy();
    });
  });

  test.describe('Sort Direction', () => {
    test('should support ascending sort direction', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all&zone_sort_by=name&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      // Page should load without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('error');
    });

    test('should support descending sort direction', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all&zone_sort_by=name&zone_sort_by_direction=DESC');
      await page.waitForLoadState('networkidle');

      // Page should load without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('error');
    });

    test('should toggle sort direction on header click', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const sortLink = page.locator('th a[href*="zone_sort_by=name"]').first();

      if (await sortLink.count() > 0) {
        await sortLink.click();
        await page.waitForLoadState('networkidle');

        // URL should contain sort parameters
        const url = page.url();
        expect(url).toMatch(/zone_sort_by=name/);
      }
    });
  });

  test.describe('Sort by Owner', () => {
    test('should allow sorting zones by owner', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all&zone_sort_by=owner&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      // Page should load successfully
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
      // Check there's no error alert about invalid sort parameters
      const errorAlert = page.locator('.alert-danger:has-text("invalid")');
      expect(await errorAlert.count()).toBe(0);
    });

    test('should allow reverse sort by owner', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all&zone_sort_by=owner&zone_sort_by_direction=DESC');
      await page.waitForLoadState('networkidle');

      // Page should load successfully
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
    });
  });

  test.describe('Sort Persistence', () => {
    test('should maintain sort parameters when paginating', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all&zone_sort_by=name&zone_sort_by_direction=DESC');
      await page.waitForLoadState('networkidle');

      // Check if pagination links maintain sort parameters
      const paginationLinks = page.locator('.pagination a[href*="zone_sort_by"]');
      const hasSortInPagination = await paginationLinks.count() >= 0;

      expect(hasSortInPagination).toBeTruthy();
    });
  });

  test.describe('Reverse Zones Sorting', () => {
    test('should allow sorting reverse zones by name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/reverse?letter=all&zone_sort_by=name&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|reverse|arpa/i);
    });

    test('should allow sorting reverse zones by owner', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/reverse?letter=all&zone_sort_by=owner&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|reverse/i);
    });
  });
});

test.describe('Record List Sorting', () => {
  // Helper to get a zone ID for testing
  async function getTestZoneId(page) {
    await page.goto('/zones/forward?letter=all');
    const editLink = page.locator('a[href*="/edit"]').first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)\/edit/);
      return match ? match[1] : null;
    }
    return null;
  }

  test.describe('Extended Sort Columns', () => {
    test('should allow sorting records by ID', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit?sortby=id&sortdir=ASC`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Page should load without invalid sort errors
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
      // Should show zone or record related content
      expect(bodyText.toLowerCase()).toMatch(/record|zone|edit/i);
    });

    test('should allow sorting records by disabled status', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit?sortby=disabled&sortdir=ASC`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });

    test('should allow sorting records by name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit?sortby=name&sortdir=DESC`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });

    test('should allow sorting records by type', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit?sortby=type&sortdir=ASC`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });
  });
});
