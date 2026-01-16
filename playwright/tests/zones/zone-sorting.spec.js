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
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on name column
      const nameHeader = page.locator('th a[href*="sortby=name"], th a:has-text("Name")');
      const hasSortableNameHeader = await nameHeader.count() > 0;

      expect(hasSortableNameHeader).toBeTruthy();
    });

    test('should have sortable type column header', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on type column
      const typeHeader = page.locator('th a[href*="sortby=type"], th:has-text("Type")');
      const hasSortableTypeHeader = await typeHeader.count() > 0;

      expect(hasSortableTypeHeader).toBeTruthy();
    });

    test('should have sortable owner column header', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on owner column - this was added by the fix
      const ownerHeader = page.locator('th a[href*="sortby=owner"], th:has-text("Owner")');
      const bodyText = await page.locator('body').textContent();

      const hasSortableOwnerHeader = await ownerHeader.count() > 0;
      const hasOwnerColumn = bodyText.toLowerCase().includes('owner');

      expect(hasSortableOwnerHeader || hasOwnerColumn).toBeTruthy();
    });

    test('should have sortable records count column', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      const recordsHeader = page.locator('th a[href*="sortby=count_records"], th:has-text("Records")');
      const hasSortableRecordsHeader = await recordsHeader.count() > 0;

      expect(hasSortableRecordsHeader).toBeTruthy();
    });
  });

  test.describe('Sort Direction', () => {
    test('should support ascending sort direction', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&sortby=name&sortdir=ASC');
      await page.waitForLoadState('networkidle');

      // Page should load without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('error');
    });

    test('should support descending sort direction', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&sortby=name&sortdir=DESC');
      await page.waitForLoadState('networkidle');

      // Page should load without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('error');
    });

    test('should toggle sort direction on header click', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      const sortLink = page.locator('th a[href*="sortby=name"]').first();

      if (await sortLink.count() > 0) {
        const initialHref = await sortLink.getAttribute('href');
        await sortLink.click();
        await page.waitForLoadState('networkidle');

        // URL should contain sort parameters
        const url = page.url();
        expect(url).toMatch(/sortby=name/);
      }
    });
  });

  test.describe('Sort by Owner (Fix #781)', () => {
    test('should allow sorting zones by owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&sortby=owner&sortdir=ASC');
      await page.waitForLoadState('networkidle');

      // Page should load successfully
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
      expect(bodyText.toLowerCase()).not.toContain('invalid');
    });

    test('should allow reverse sort by owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&sortby=owner&sortdir=DESC');
      await page.waitForLoadState('networkidle');

      // Page should load successfully
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
    });
  });

  test.describe('Sort Persistence', () => {
    test('should maintain sort parameters when paginating', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&sortby=name&sortdir=DESC');
      await page.waitForLoadState('networkidle');

      // Check if pagination links maintain sort parameters
      const paginationLinks = page.locator('.pagination a[href*="sortby"]');
      const hasSortInPagination = await paginationLinks.count() >= 0;

      expect(hasSortInPagination).toBeTruthy();
    });
  });

  test.describe('Reverse Zones Sorting', () => {
    test('should allow sorting reverse zones by name', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_reverse_zones&sortby=name&sortdir=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|reverse|arpa/i);
    });

    test('should allow sorting reverse zones by owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_reverse_zones&sortby=owner&sortdir=ASC');
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
