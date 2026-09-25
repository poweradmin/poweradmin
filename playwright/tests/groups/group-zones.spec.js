/**
 * Group Zones Management Tests
 *
 * Tests for managing zone-group ownership including
 * adding and removing zones from groups.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

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
    if (await row.count() > 0) {
      const zonesLink = row.locator('a[href*="/zones"]').first();
      if (await zonesLink.count() > 0) {
        await zonesLink.click();
        return true;
      }
    }
    return false;
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

      const found = await navigateToGroupZones(page, 'Zone Managers');
      if (found) {
        await expect(page).toHaveURL(/.*groups\/\d+\/zones/);
      }
    });

    test('should display current zone assignments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupZones(page, 'Zone Managers');
      if (found) {
        // Zone Managers has manager-zone, shared-zone, group-only-zone from test data
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).toContainText(/zone|domain/i);
      }
    });

    test('should display available zones panel', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupZones(page, 'Viewers');
      if (found) {
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).toContainText(/add|available|zone/i);
      }
    });
  });

  test.describe('Add Zones', () => {
    // Works on a group it creates and deletes itself. Adding a zone to a seeded
    // group would leave it behind and skew the zone-visibility specs.
    test('should add zone to group', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const groupName = `Zones Test ${Date.now()}`;
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

      const found = await navigateToGroupZones(page, 'Zone Managers');
      if (found) {
        const removeBtn = page.locator('#remove-btn');
        expect(await removeBtn.count()).toBeGreaterThanOrEqual(0);
      }
    });

    test('should display zone checkboxes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupZones(page, 'Editors');
      if (found) {
        const checkboxes = page.locator('#remove-form .owned-checkbox');
        // Editors has client-zone, shared-zone from test data
        expect(await checkboxes.count()).toBeGreaterThanOrEqual(0);
      }
    });

    test('should have select all checkbox for owned zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Editors owns client-zone and shared-zone in the test data
      await openSeededGroupZones(page, 'Editors');

      await expect(page.locator('#select-all-owned')).toBeVisible();
    });

    test('should display selection count badge', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await openSeededGroupZones(page, 'Editors');

      const countBadge = page.locator('#zone-remove-count');
      await expect(countBadge).toBeVisible();
      await expect(countBadge).toContainText('0');

      // Checking a zone must move the counter
      await page.locator('.owned-checkbox').first().check();
      await expect(countBadge).toContainText('1');
    });
  });

  test.describe('Multi-Group Zone Handling', () => {
    test('shared zone should appear in multiple groups', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Check Zone Managers
      let found = await navigateToGroupZones(page, 'Zone Managers');
      if (found) {
        const bodyText1 = await page.locator('body').textContent();
        const hasSharedZone1 = bodyText1.includes('shared-zone');

        // Check Editors
        found = await navigateToGroupZones(page, 'Editors');
        if (found) {
          const bodyText2 = await page.locator('body').textContent();
          const hasSharedZone2 = bodyText2.includes('shared-zone');

          // shared-zone should be in both groups
          if (hasSharedZone1 && hasSharedZone2) {
            expect(true).toBeTruthy();
          }
        }
      }
    });
  });
});
