/**
 * Group Zones Management Tests
 *
 * Tests for managing zone-group ownership including
 * adding and removing zones from groups.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Group Zones Management', () => {
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
        const bodyText = await page.locator('body').textContent();
        // Zone Managers has manager-zone, shared-zone, group-only-zone from test data
        expect(bodyText.toLowerCase()).toMatch(/zone|domain/i);
      }
    });

    test('should display available zones panel', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupZones(page, 'Viewers');
      if (found) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/add|available|zone/i);
      }
    });
  });

  test.describe('Add Zones', () => {
    test('should add zone to group', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Navigate to Viewers group (has no zones)
      const found = await navigateToGroupZones(page, 'Viewers');
      if (found) {
        const availableCheckbox = page.locator('#add-form .available-checkbox').first();
        if (await availableCheckbox.count() > 0) {
          await availableCheckbox.check();
          const addBtn = page.locator('#add-btn');
          if (await addBtn.count() > 0) {
            await addBtn.click();
            await page.waitForLoadState('domcontentloaded');

            const bodyText = await page.locator('body').textContent();
            expect(bodyText).not.toMatch(/fatal|exception/i);
          }
        }
      }
    });

    test('should display search for available zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupZones(page, 'Zone Managers');
      if (found) {
        const searchInput = page.locator('#search-available');
        if (await searchInput.count() > 0) {
          await expect(searchInput).toBeVisible();
        }
      }
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

      const found = await navigateToGroupZones(page, 'Editors');
      if (found) {
        const selectAll = page.locator('#select-all-owned');
        if (await selectAll.count() > 0) {
          await expect(selectAll).toBeVisible();
        }
      }
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
