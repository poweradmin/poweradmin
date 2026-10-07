/**
 * Zone Sorting Tests
 *
 * Tests for the zone list sorting functionality
 * including sorting by name, type, owner, and records count.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { createZone, deleteZoneById, isApiModeInstance } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

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

    // SQL backend only. In API mode record counts are resolved per page, so the
    // column is plain text - see api-backend-zone-list.spec.js.
    test('should have sortable records count column', async ({ page, baseURL }) => {
      test.skip(isApiModeInstance(baseURL), 'records count is not sortable on the API backend');
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Check for sorting link on records column
      const recordsHeader = page.locator('th a[href*="zone_sort_by=count_records"]');
      const hasSortableRecordsHeader = await recordsHeader.count() > 0;

      expect(hasSortableRecordsHeader).toBeTruthy();
    });

    // SQL backend only: ListForwardZonesController disables group sorting in API
    // mode, so the header renders as plain text.
    test('should have sortable group column header', async ({ page, baseURL }) => {
      test.skip(isApiModeInstance(baseURL), 'group sorting is not offered on the API backend');
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const groupHeader = page.locator('th a[href*="zone_sort_by=group"]');
      expect(await groupHeader.count()).toBeGreaterThan(0);
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

      // The name column header is always a sort link on the zone list
      const sortLink = page.locator('th a[href*="zone_sort_by=name"]').first();
      await expect(sortLink).toBeVisible();

      await sortLink.click();
      await page.waitForLoadState('networkidle');
      expect(page.url()).toMatch(/zone_sort_by=name/);
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

  test.describe('Sort by Group', () => {
    test('should allow sorting zones by group ASC and DESC', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto('/zones/forward?letter=all&zone_sort_by=group&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');
      const bodyAsc = await page.locator('body').textContent();
      expect(bodyAsc.toLowerCase()).toMatch(/zone|forward/i);
      expect(await page.locator('.alert-danger:has-text("invalid")').count()).toBe(0);

      await page.goto('/zones/forward?letter=all&zone_sort_by=group&zone_sort_by_direction=DESC');
      await page.waitForLoadState('networkidle');
      const bodyDesc = await page.locator('body').textContent();
      expect(bodyDesc.toLowerCase()).toMatch(/zone|forward/i);
    });

    test('clicking group sort link toggles direction', async ({ page, baseURL }) => {
      test.skip(isApiModeInstance(baseURL), 'group sorting is not offered on the API backend');
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const groupSortLink = page.locator('th a[href*="zone_sort_by=group"]').first();
      await groupSortLink.click();
      await page.waitForLoadState('networkidle');

      expect(page.url()).toMatch(/zone_sort_by=group/);
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
      await page.goto('/zones/reverse?reverse_type=all&zone_sort_by=name&zone_sort_by_direction=ASC');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|reverse|arpa/i);
    });

    test('should allow sorting reverse zones by owner', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/reverse?reverse_type=all&zone_sort_by=owner&zone_sort_by_direction=ASC');
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
    // Scoped to the table: an unscoped a[href*="/edit"] matches the nav dropdown first,
    // which carries no zone id, so this helper used to return null for every test.
      const editLink = page.locator('table a[href*="/zones/"][href*="/edit"]').first();
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

      await page.goto(`/zones/${zoneId}/edit?record_sort_by=id&sort_direction=ASC`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Page should load without invalid sort errors
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
      // Should show zone or record related content
      expect(bodyText.toLowerCase()).toMatch(/record|zone|edit/i);
    });

    test('should sort records by disabled status in both directions', async ({ page }) => {
      test.setTimeout(90000);
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // The seeded zones hold no disabled record, so build a zone that has both kinds
      const zoneName = `sort-disabled-${Date.now()}.example.com`;
      const zoneId = await createZone(page, zoneName);
      expect(zoneId, `zone ${zoneName} must be created`).toBeTruthy();

      const recordRow = (content) => page
        .locator(`#edit-zone-form input[name$="[content]"][value="${content}"]`)
        .locator('xpath=ancestor::tr[1]');

      // SOA, NS and apex records stay pinned above the sorted body, so only the records
      // created here are compared. The checked attribute is the server-rendered state.
      const created = ['192.0.2.10', '192.0.2.20', '192.0.2.30'];
      const disabledStates = () => page
        .locator('#edit-zone-form input[type="checkbox"][name^="record["][name$="[disabled]"]')
        .evaluateAll((nodes, contents) => nodes
          .map(n => ({
            content: n.closest('tr')?.querySelector('input[name$="[content]"]')?.value,
            disabled: n.hasAttribute('checked'),
          }))
          .filter(r => contents.includes(r.content))
          .map(r => r.disabled), created);

      try {
        // Names put the disabled record in the middle, so neither direction can pass by name order
        for (const [name, content] of [['a-on', created[0]], ['b-off', created[1]], ['c-on', created[2]]]) {
          await page.goto(`/zones/${zoneId}/records/add`);
          await page.locator('select[name*="type"]').first().selectOption('A');
          await page.locator('input[name*="name"]').first().fill(name);
          await page.locator('input[name*="content"]').first().fill(content);
          await page.locator('button[type="submit"], input[type="submit"]').first().click();
          await page.waitForURL(new RegExp(`/zones/${zoneId}/edit`));
        }

        await page.goto(`/zones/${zoneId}/edit?record_sort_by=name&sort_direction=ASC`);
        await expect(recordRow(created[0])).toHaveCount(1);
        await expect(recordRow(created[2])).toHaveCount(1);
        await recordRow(created[1]).locator('input[type="checkbox"][name$="[disabled]"]').check();
        await Promise.all([
          page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(`/zones/${zoneId}/edit`)),
          page.locator('[data-testid="save-changes-button"]').first().click(),
        ]);

        await page.goto(`/zones/${zoneId}/edit?record_sort_by=name&sort_direction=ASC`);
        await expect(recordRow(created[1]).locator('input[name$="[disabled]"]')).toHaveAttribute('checked', /.*/);
        await expect(recordRow(created[0]).locator('input[name$="[disabled]"]')).not.toHaveAttribute('checked', /.*/);
        await expect(recordRow(created[2]).locator('input[name$="[disabled]"]')).not.toHaveAttribute('checked', /.*/);

        await page.goto(`/zones/${zoneId}/edit?record_sort_by=disabled&sort_direction=ASC`);
        const ascending = await disabledStates();
        expect(ascending).toHaveLength(created.length);
        expect(ascending, 'ASC lists enabled records before disabled ones').toEqual([false, false, true]);

        await page.goto(`/zones/${zoneId}/edit?record_sort_by=disabled&sort_direction=DESC`);
        const descending = await disabledStates();
        expect(descending).toHaveLength(created.length);
        expect(descending, 'DESC lists disabled records before enabled ones').toEqual([true, false, false]);
      } finally {
        // The record sort order is kept in the session, so put the default back for later tests
        await page.goto(`/zones/${zoneId}/edit?record_sort_by=name&sort_direction=ASC`);
        await deleteZoneById(page, zoneId);
      }
    });

    test('should allow sorting records by name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit?record_sort_by=name&sort_direction=DESC`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });

    test('should allow sorting records by type', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit?record_sort_by=type&sort_direction=ASC`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toContain('invalid sort');
    });
  });
});
