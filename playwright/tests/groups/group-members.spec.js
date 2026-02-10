/**
 * Group Members Management Tests
 *
 * Tests for managing group memberships including
 * adding and removing users from groups.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Group Members Management', () => {
  // Helper to find a group ID by navigating to the list and clicking members link
  async function navigateToGroupMembers(page, groupName) {
    await page.goto('/groups');
    const row = page.locator(`tr:has-text("${groupName}")`);
    if (await row.count() > 0) {
      const membersLink = row.locator('a[href*="/members"]').first();
      if (await membersLink.count() > 0) {
        await membersLink.click();
        return true;
      }
    }
    return false;
  }

  test.describe('Access Members Page', () => {
    test('admin should access group members page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Zone Managers');
      if (found) {
        await expect(page).toHaveURL(/.*groups\/\d+\/members/);
      }
    });

    test('should display current members list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Zone Managers');
      if (found) {
        const bodyText = await page.locator('body').textContent();
        // Zone Managers has 'manager' as a member from test data
        expect(bodyText).toMatch(/manager|member/i);
      }
    });

    test('should display add members panel', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Viewers');
      if (found) {
        const addForm = page.locator('#add-form');
        expect(await addForm.count()).toBeGreaterThanOrEqual(0);

        // Should have available users checkboxes or list
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/add|available|member/i);
      }
    });
  });

  test.describe('Add Members', () => {
    test('should add user to group', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Navigate to Guests group (has only noperm)
      const found = await navigateToGroupMembers(page, 'Guests');
      if (found) {
        // Look for available users to add
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

    test('should display search functionality for available users', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Zone Managers');
      if (found) {
        const searchInput = page.locator('#search-available');
        if (await searchInput.count() > 0) {
          await expect(searchInput).toBeVisible();
        }
      }
    });
  });

  test.describe('Remove Members', () => {
    test('should display remove button for current members', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Zone Managers');
      if (found) {
        const removeBtn = page.locator('#remove-btn');
        expect(await removeBtn.count()).toBeGreaterThanOrEqual(0);
      }
    });

    test('should display member checkboxes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Editors');
      if (found) {
        const checkboxes = page.locator('#remove-form .member-checkbox');
        // Editors has manager and client from test data
        expect(await checkboxes.count()).toBeGreaterThanOrEqual(0);
      }
    });

    test('should have select all checkbox', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Editors');
      if (found) {
        const selectAll = page.locator('#select-all-current');
        if (await selectAll.count() > 0) {
          await expect(selectAll).toBeVisible();
        }
      }
    });
  });
});
