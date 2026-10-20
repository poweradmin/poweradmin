/**
 * Group Zones Management Tests
 *
 * Tests for managing zone-group ownership including
 * adding and removing zones from groups.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { uniqueName } from '../../helpers/zones.js';

test.describe.configure({ mode: 'serial' });

test.describe('Group Zones Management', () => {
  async function createGroup(page, groupName) {
    await page.goto('/groups/add');
    await page.locator('input#name').fill(groupName);
    const select = page.locator('select#perm_templ');
    const options = select.locator('option:not([disabled])');
    // <option> elements are never visible to Playwright; assert presence instead
    await expect(options).not.toHaveCount(0);
    await select.selectOption(await options.first().getAttribute('value'));
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
  }

  async function deleteGroup(page, groupName) {
    await page.goto('/groups');
    const row = page.locator(`tr:has-text("${groupName}")`);
    if (await row.count() === 0) {
      return;
    }
    await row.locator('a[href*="/delete"]').first().click();
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
  }

  async function navigateToGroupZones(page, groupName) {
    await page.goto('/groups');
    const row = page.locator(`tr:has-text("${groupName}")`);
    await expect(row, `group ${groupName} must be listed`).not.toHaveCount(0);
    await row.locator('a[href*="/zones"]').first().click();
    return true;
  }

  // Creates a group that owns the zone, runs fn on its zones page, then deletes the group
  async function withGroupOwningZone(page, zone, fn) {
    const groupName = uniqueName('gzo');
    await createGroup(page, groupName);
    try {
      await navigateToGroupZones(page, groupName);
      await page.locator(`#add-form .available-checkbox[value="${zone.id}"]`).check();
      await page.locator('#add-btn').click();
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator(`#remove-form .owned-checkbox[value="${zone.id}"]`)).toHaveCount(1);
      await fn();
    } finally {
      await deleteGroup(page, groupName);
    }
  }

  /** Opens the zones page of a seeded group, which the test data always provides. */
  async function openSeededGroupZones(page, groupName) {
    await page.goto('/groups');
    const row = page.locator(`tr:has-text("${groupName}")`);
    await expect(row).not.toHaveCount(0);
    await row.locator('a[href*="/zones"]').first().click();
    await expect(page).toHaveURL(/\/zones/);
  }

  test.describe('Access Zones Page', () => {
    test('admin should access group zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await navigateToGroupZones(page, 'Zone Managers');
      await expect(page).toHaveURL(/.*groups\/\d+\/zones/);
    });

    test('should display current zone assignments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await navigateToGroupZones(page, 'Zone Managers');
      // Zone Managers has manager-zone, shared-zone, group-only-zone from test data
      await expect(page.locator('#remove-form tbody tr').filter({
        has: page.getByRole('cell', { name: 'group-only-zone.example.com', exact: true }),
      })).toHaveCount(1);
    });

    test('should display available zones panel', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await navigateToGroupZones(page, 'Viewers');
      // Zones that Viewers does not hold are listed with a checkbox and name
      await expect(page.locator('#add-form #available-zones-body tr').first()).toBeVisible();
      await expect(page.locator('#add-form .available-checkbox').first()).toBeVisible();
    });
  });

  test.describe('Add Zones', () => {
    // Works on a group it creates and deletes itself. Adding a zone to a seeded
    // group would leave it behind and skew the zone-visibility specs.
    test('should add zone to group', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const groupName = uniqueName('gz');
      await createGroup(page, groupName);

      try {
        expect(await navigateToGroupZones(page, groupName)).toBe(true);

        const availableCheckbox = page.locator('#add-form .available-checkbox').first();
        await expect(availableCheckbox).toHaveCount(1);

        const addedZoneId = await availableCheckbox.getAttribute('value');
        await availableCheckbox.check();
        await page.locator('#add-btn').click();
        await page.waitForLoadState('domcontentloaded');

        // The zone must now appear as assigned, not merely not crash.
        await expect(page.locator(`#remove-form input[value="${addedZoneId}"]`)).toHaveCount(1);
      } finally {
        await deleteGroup(page, groupName);
      }
    });

    test('should display search for available zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await openSeededGroupZones(page, 'Zone Managers');

      await expect(page.locator('#search-available')).toBeVisible();
    });
  });

  test.describe('Remove Zones', () => {
    test('should display remove button for owned zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await navigateToGroupZones(page, 'Zone Managers');
      const removeBtn = page.locator('#remove-btn');
      // Zone Managers owns zones in the test data, so the remove form renders
      await expect(removeBtn).toHaveCount(1);
    });

    test('should display zone checkboxes', async ({ page, tempZone }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await withGroupOwningZone(page, tempZone, async () => {
        await expect(page.locator('#remove-form .owned-checkbox')).toHaveCount(1);
      });
    });

    test('should have select all checkbox for owned zones', async ({ page, tempZone }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await withGroupOwningZone(page, tempZone, async () => {
        await expect(page.locator('#select-all-owned')).toBeVisible();
      });
    });

    test('should display selection count badge', async ({ page, tempZone }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await withGroupOwningZone(page, tempZone, async () => {
        const countBadge = page.locator('#zone-remove-count');
        await expect(countBadge).toBeVisible();
        await expect(countBadge).toContainText('0');

        // Checking a zone must move the counter
        await page.locator('.owned-checkbox').first().check();
        await expect(countBadge).toContainText('1');
      });
    });
  });

  test.describe('Multi-Group Zone Handling', () => {
    test('shared zone should appear in multiple groups', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // shared-zone is owned by both groups in the test data
      await navigateToGroupZones(page, 'Zone Managers');
      await expect(page.locator('body')).toContainText('shared-zone');

      await navigateToGroupZones(page, 'Editors');
      await expect(page.locator('body')).toContainText('shared-zone');
    });
  });
});
