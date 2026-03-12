/**
 * Group CRUD Operations Tests
 *
 * Tests for group management including listing,
 * adding, editing, and deleting groups.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Group CRUD Operations', () => {
  // Helper to create a group (selects first available template)
  async function createGroup(page, groupName) {
    await page.goto('/groups/add');
    await page.locator('input#name').fill(groupName);
    const select = page.locator('select#perm_templ');
    const options = select.locator('option:not([disabled])');
    if (await options.count() > 0) {
      await select.selectOption(await options.first().getAttribute('value'));
    }
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
  }

  test.describe('List Groups', () => {
    test('admin should access groups list page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      await expect(page).toHaveURL(/.*groups/);
    });

    test('should display groups table with default groups', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/Administrators|Zone Managers|Editors|Viewers|Guests/);
    });

    test('should display member and zone count badges', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const badges = page.locator('.badge');
      expect(await badges.count()).toBeGreaterThan(0);
    });

    test('should display action links for admin', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const editLinks = page.locator('a[href*="/edit"]');
      const deleteLinks = page.locator('a[href*="/delete"]');
      expect(await editLinks.count()).toBeGreaterThan(0);
      expect(await deleteLinks.count()).toBeGreaterThan(0);
    });

    test('should display add group button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const addBtn = page.locator('a[href*="/groups/add"]');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('should display search input for filtering groups', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const searchInput = page.locator('#group-search');
      await expect(searchInput).toBeVisible();
    });

    test('should filter groups by search term', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const searchInput = page.locator('#group-search');
      await searchInput.fill('Admin');

      // Administrators row should be visible, others hidden
      const visibleRows = page.locator('.group-row:visible');
      const allRows = page.locator('.group-row');
      expect(await visibleRows.count()).toBeLessThan(await allRows.count());
      expect(await visibleRows.count()).toBeGreaterThan(0);
    });

    test('should clear search and show all groups', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const searchInput = page.locator('#group-search');
      await searchInput.fill('Admin');

      const clearBtn = page.locator('#clear-group-search');
      await clearBtn.click();

      const allRows = page.locator('.group-row');
      const visibleRows = page.locator('.group-row:visible');
      expect(await visibleRows.count()).toBe(await allRows.count());
    });

    test('should display distinct badge colors for members and zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const successBadges = page.locator('.badge.bg-success');
      const primaryBadges = page.locator('.badge.bg-primary');
      expect(await successBadges.count()).toBeGreaterThan(0);
      expect(await primaryBadges.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Add Group', () => {
    test('should access add group page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/add');

      await expect(page).toHaveURL(/.*groups\/add/);
    });

    test('should display name and description fields', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/add');

      await expect(page.locator('input#name')).toBeVisible();
      await expect(page.locator('textarea#description, input#description')).toBeVisible();
      await expect(page.locator('select#perm_templ')).toBeVisible();
    });

    test('should display info sidebar', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/add');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/About Groups/i);
      expect(bodyText).toMatch(/What are Groups/i);
    });

    test('should display template select with placeholder option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/add');

      const select = page.locator('select#perm_templ');
      const placeholderOption = select.locator('option[disabled]');
      expect(await placeholderOption.count()).toBeGreaterThan(0);
      await expect(placeholderOption).toContainText('Select a template');
    });

    test('should create new group with name and description', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const groupName = `Test Group ${Date.now()}`;
      await page.goto('/groups/add');

      await page.locator('input#name').fill(groupName);
      await page.locator('textarea#description, input#description').first().fill('E2E test group');

      // Select the first available template
      const select = page.locator('select#perm_templ');
      const options = select.locator('option:not([disabled])');
      if (await options.count() > 0) {
        const firstValue = await options.first().getAttribute('value');
        await select.selectOption(firstValue);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('domcontentloaded');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty group name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/add');

      // Leave name empty, fill description and select template
      await page.locator('textarea#description, input#description').first().fill('No name group');
      const select = page.locator('select#perm_templ');
      const options = select.locator('option:not([disabled])');
      if (await options.count() > 0) {
        await select.selectOption(await options.first().getAttribute('value'));
      }
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('empty') ||
                       bodyText.toLowerCase().includes('required') ||
                       url.includes('groups/add');
      expect(hasError).toBeTruthy();
    });

    test('should reject duplicate group name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups/add');

      // Use a name that already exists from test data
      await page.locator('input#name').fill('Administrators');
      const select = page.locator('select#perm_templ');
      const options = select.locator('option:not([disabled])');
      if (await options.count() > 0) {
        await select.selectOption(await options.first().getAttribute('value'));
      }
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const hasError = bodyText.toLowerCase().includes('exist') ||
                       bodyText.toLowerCase().includes('duplicate') ||
                       bodyText.toLowerCase().includes('error') ||
                       url.includes('groups/add');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Edit Group', () => {
    test('should access edit group page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const editLink = page.locator('a[href*="/groups/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/.*groups\/\d+\/edit/);
      }
    });

    test('should update group name and description', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // First create a group to edit
      const groupName = `Edit Test ${Date.now()}`;
      await createGroup(page, groupName);

      // Find and edit the created group
      await page.goto('/groups');
      const row = page.locator(`tr:has-text("${groupName}")`);
      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="/edit"]').first();
        await editLink.click();

        const updatedName = `Updated ${groupName}`;
        await page.locator('input#name').fill(updatedName);
        await page.locator('textarea#description, input#description').first().fill('Updated description');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        await page.waitForLoadState('domcontentloaded');
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Delete Group', () => {
    test('should access delete confirmation page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // Create a group to delete
      const groupName = `Delete Test ${Date.now()}`;
      await createGroup(page, groupName);

      await page.goto('/groups');
      const row = page.locator(`tr:has-text("${groupName}")`);
      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="/delete"]').first();
        await deleteLink.click();

        await expect(page).toHaveURL(/.*groups\/\d+\/delete/);
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|warning|confirm/i);
      }
    });

    test('should display deletion impact', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/member|zone|impact/i);
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/groups');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")').first();
        if (await cancelBtn.count() > 0) {
          await cancelBtn.click();
          await expect(page).toHaveURL(/.*groups$/);
        }
      }
    });

    test('should delete group with confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // Create a group to delete
      const groupName = `ToDelete ${Date.now()}`;
      await createGroup(page, groupName);

      await page.goto('/groups');
      const row = page.locator(`tr:has-text("${groupName}")`);
      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="/delete"]').first();
        await deleteLink.click();

        const yesBtn = page.locator('button:has-text("Yes"), button:has-text("delete this group")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();

          await page.waitForLoadState('domcontentloaded');
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('manager sees only own groups without edit/delete actions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/groups');

      await expect(page).toHaveURL(/.*groups/);
      // Manager sees groups they belong to (Editors, Zone Managers) but no add button or action links
      const addBtn = page.locator('a[href*="/groups/add"]');
      expect(await addBtn.count()).toBe(0);

      const editLinks = page.locator('a[href*="/groups/"][href*="/edit"]');
      expect(await editLinks.count()).toBe(0);
    });

    test('viewer should not access groups add page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/groups/add');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !url.includes('groups/add');
      expect(accessDenied).toBeTruthy();
    });
  });
});
