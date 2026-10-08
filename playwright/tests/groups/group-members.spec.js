/**
 * Group Members Management Tests
 *
 * Tests for managing group memberships including
 * adding and removing users from groups.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { uniqueName } from '../../helpers/zones.js';

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

  // Opens the page of a group; the callers use seeded or just-created groups
  async function navigateToGroupMembers(page, groupName) {
    await page.goto('/groups');
    const row = page.locator(`tr:has-text("${groupName}")`);
    await expect(row, `group ${groupName} must be listed`).not.toHaveCount(0);
    await row.locator('a[href*="/members"]').first().click();
    return true;
  }

  // Creates a group with one member, runs fn on its members page, then deletes the group
  async function withGroupHavingMember(page, fn) {
    const groupName = uniqueName('gmo');
    await createGroup(page, groupName);
    try {
      await navigateToGroupMembers(page, groupName);
      const available = page.locator('#add-form .available-checkbox').first();
      await expect(available).toHaveCount(1);
      const userId = await available.getAttribute('value');
      await available.check();
      await page.locator('#add-btn').click();
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator(`#remove-form .member-checkbox[value="${userId}"]`)).toHaveCount(1);
      await fn();
    } finally {
      await deleteGroup(page, groupName);
    }
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

      await navigateToGroupMembers(page, 'Zone Managers');
      await expect(page).toHaveURL(/.*groups\/\d+\/members/);
    });

    test('should display current members list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await navigateToGroupMembers(page, 'Zone Managers');
      // Zone Managers has 'manager' as a member from test data; match its username cell
      await expect(page.locator('#remove-form tbody tr').filter({
        has: page.getByRole('cell', { name: users.manager.username, exact: true }),
      })).toHaveCount(1);
    });

    test('should display add members panel', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await navigateToGroupMembers(page, 'Viewers');
      await expect(page.locator('#add-form')).toHaveCount(1);

      // At least one user is available to add, listed with a checkbox and username
      await expect(page.locator('#add-form #available-users-body .user-row').first()).toBeVisible();
      await expect(page.locator('#add-form .available-checkbox').first()).toBeVisible();
    });
  });

  test.describe('Add Members', () => {
    // Works on a group it creates and deletes itself. Adding a member to a seeded
    // group would leave it behind and skew the fixtures other specs assert against.
    test('should add user to group', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const groupName = uniqueName('gm');
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

      await navigateToGroupMembers(page, 'Zone Managers');
      const removeBtn = page.locator('#remove-btn');
      // Zone Managers has members in the test data, so the remove form renders
      await expect(removeBtn).toHaveCount(1);
    });

    test('should display member checkboxes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await withGroupHavingMember(page, async () => {
        await expect(page.locator('#remove-form .member-checkbox')).toHaveCount(1);
      });
    });

    test('should have select all checkbox', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await withGroupHavingMember(page, async () => {
        await expect(page.locator('#select-all-current')).toBeVisible();
      });
    });

    test('should display selection count badge', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await withGroupHavingMember(page, async () => {
        const countBadge = page.locator('#member-remove-count');
        await expect(countBadge).toBeVisible();
        await expect(countBadge).toContainText('0');

        // Checking a member must move the counter
        await page.locator('.member-checkbox').first().check();
        await expect(countBadge).toContainText('1');
      });
    });
  });
});
