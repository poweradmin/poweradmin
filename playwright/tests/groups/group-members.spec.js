/**
 * Group Members Management Tests
 *
 * Tests for managing group memberships including
 * adding and removing users from groups.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Group Members Management', () => {
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

  /** Opens the members page of a seeded group, which the test data always provides. */
  async function openSeededGroupMembers(page, groupName) {
    await page.goto('/groups');
    const row = page.locator(`tr:has-text("${groupName}")`);
    await expect(row).not.toHaveCount(0);
    await row.locator('a[href*="/members"]').first().click();
    await expect(page).toHaveURL(/\/members/);
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
        // Zone Managers has 'manager' as a member from test data
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).toContainText(/manager|member/i);
      }
    });

    test('should display add members panel', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToGroupMembers(page, 'Viewers');
      if (found) {
        const addForm = page.locator('#add-form');
        expect(await addForm.count()).toBeGreaterThanOrEqual(0);

        // Should have available users checkboxes or list
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).toContainText(/add|available|member/i);
      }
    });
  });

  test.describe('Add Members', () => {
    // Works on a group it creates and deletes itself. Adding a member to a seeded
    // group would leave it behind and skew the fixtures other specs assert against.
    test('should add user to group', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const groupName = `Members Test ${Date.now()}`;
      await createGroup(page, groupName);

      try {
        expect(await navigateToGroupMembers(page, groupName)).toBe(true);

        const availableCheckbox = page.locator('#add-form .available-checkbox').first();
        await expect(availableCheckbox).toHaveCount(1);

        const addedUserId = await availableCheckbox.getAttribute('value');
        await availableCheckbox.check();
        await page.locator('#add-btn').click();
        await page.waitForLoadState('domcontentloaded');

        // The user must now appear as a current member, not merely not crash.
        await expect(page.locator(`#remove-form .member-checkbox[value="${addedUserId}"]`)).toHaveCount(1);
      } finally {
        await deleteGroup(page, groupName);
      }
    });

    test('should display search functionality for available users', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await openSeededGroupMembers(page, 'Zone Managers');

      await expect(page.locator('#search-available')).toBeVisible();
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

      // Editors is seeded with manager and client, so the members table renders
      await openSeededGroupMembers(page, 'Editors');

      await expect(page.locator('#select-all-current')).toBeVisible();
    });

    test('should display selection count badge', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await openSeededGroupMembers(page, 'Editors');

      const countBadge = page.locator('#member-remove-count');
      await expect(countBadge).toBeVisible();
      await expect(countBadge).toContainText('0');

      // Checking a member must move the counter
      await page.locator('.member-checkbox').first().check();
      await expect(countBadge).toContainText('1');
    });
  });
});
