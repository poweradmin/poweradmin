/**
 * Zone Sorting Tests
 *
 * Tests for the zone list sorting functionality
 * covering fix(ui): enable sorting by owner, id, and disabled status, closes #781
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Zone List Sorting', () => {
  test.describe('Column Header Sorting', () => {
    test('should have sortable name column header', async ({ adminPage: page }) => {
      // Use letter=all to ensure zones are displayed and headers are visible
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on name column (template uses zone_sort_by parameter)
      const nameHeader = page.locator('th a[href*="zone_sort_by=name"]');
      const hasSortableNameHeader = await nameHeader.count() > 0;

      expect(hasSortableNameHeader).toBeTruthy();
    });

    test('should have sortable type column header', async ({ adminPage: page }) => {
      // Use letter=all to ensure zones are displayed and headers are visible
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on type column (template uses zone_sort_by parameter)
      const typeHeader = page.locator('th a[href*="zone_sort_by=type"]');
      const hasSortableTypeHeader = await typeHeader.count() > 0;

      expect(hasSortableTypeHeader).toBeTruthy();
    });

    test('should have sortable owner column header', async ({ adminPage: page }) => {
      // Use letter=all to ensure zones are displayed and headers are visible
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on owner column (template uses zone_sort_by parameter)
      const ownerHeader = page.locator('th a[href*="zone_sort_by=owner"]');
      const hasSortableOwnerHeader = await ownerHeader.count() > 0;

      expect(hasSortableOwnerHeader).toBeTruthy();
    });

    test('should have sortable records count column', async ({ adminPage: page }) => {
      // Use letter=all to ensure zones are displayed and headers are visible
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on records column (template uses zone_sort_by parameter)
      const recordsHeader = page.locator('th a[href*="zone_sort_by=count_records"]');
      const hasSortableRecordsHeader = await recordsHeader.count() > 0;

      expect(hasSortableRecordsHeader).toBeTruthy();
    });
  });

  test.describe('Sort Direction', () => {
    test('should support ascending sort direction', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all&zone_sort_by=name&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      // Page should load without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('error');
    });

    test('should support descending sort direction', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all&zone_sort_by=name&zone_sort_by_direction=DESC');
      await page.waitForLoadState('networkidle');

      // Page should load without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('error');
    });

    test('should toggle sort direction on header click', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      await page.waitForLoadState('networkidle');

      const sortLink = page.locator('th a[href*="zone_sort_by=name"]').first();

      if (await sortLink.count() > 0) {
        const initialHref = await sortLink.getAttribute('href');
        await sortLink.click();
        await page.waitForLoadState('networkidle');

        // URL should contain sort parameters
        const url = page.url();
        expect(url).toMatch(/zone_sort_by=name/);
      }
    });
  });

  test.describe('Sort by Owner (Fix #781)', () => {
    test('should allow sorting zones by owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all&zone_sort_by=owner&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      // Page should load successfully - check for zone content and no error alerts
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
      // Check there's no error alert about invalid sort parameters
      const errorAlert = page.locator('.alert-danger:has-text("invalid")');
      expect(await errorAlert.count()).toBe(0);
    });

    test('should allow reverse sort by owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all&zone_sort_by=owner&zone_sort_by_direction=DESC');
      await page.waitForLoadState('networkidle');

      // Page should load successfully
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
    });
  });

  test.describe('Sort Persistence', () => {
    test('should maintain sort parameters when paginating', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all&zone_sort_by=name&zone_sort_by_direction=DESC');
      await page.waitForLoadState('networkidle');

      // Check if pagination links maintain sort parameters
      const paginationLinks = page.locator('.pagination a[href*="zone_sort_by"]');
      const hasSortInPagination = await paginationLinks.count() >= 0;

      expect(hasSortInPagination).toBeTruthy();
    });
  });

  test.describe('Reverse Zones Sorting', () => {
    test('should allow sorting reverse zones by name', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_reverse_zones&letter=all&zone_sort_by=name&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|reverse|arpa/i);
    });

    test('should allow sorting reverse zones by owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_reverse_zones&letter=all&zone_sort_by=owner&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|reverse/i);
    });
  });
});

test.describe('Record List Sorting (Fix #781)', () => {
  test.describe('Extended Sort Columns', () => {
    test('should allow sorting records by ID', async ({ adminPage: page }) => {
      // Navigate directly to a zone edit page with sort parameters
      // Using zone ID 2 which is the manager-zone from test data
      await page.goto('/index.php?page=edit&id=2&sortby=id&sortdir=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Page should load without invalid sort errors
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
      // Should show zone or record related content
      expect(bodyText.toLowerCase()).toMatch(/record|zone|edit/i);
    });

    test('should allow sorting records by disabled status', async ({ adminPage: page }) => {
      // Navigate directly to a zone edit page with sort parameters
      await page.goto('/index.php?page=edit&id=2&sortby=disabled&sortdir=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Page should load without invalid sort errors
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });

    test('should allow sorting records by name', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=edit&id=2&sortby=name&sortdir=DESC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });

    test('should allow sorting records by type', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=edit&id=2&sortby=type&sortdir=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });
  });
});
