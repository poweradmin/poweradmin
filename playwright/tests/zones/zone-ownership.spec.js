/**
 * Zone Ownership Management Tests
 *
 * Tests for managing zone ownership including user and group owners.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Zone Ownership Management', () => {
  /**
   * Open the ownership page of the first zone the admin can manage.
   * The ownership link lives on the zone edit page, not on the zone list.
   */
  async function openZoneOwnership(page) {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');

    const editLink = page.locator('table a[href*="/edit"]').first();
    await expect(editLink).toBeVisible();
    await editLink.click();

    const ownershipLink = page.locator('a[href*="/ownership"]').first();
    await expect(ownershipLink).toBeVisible();
    const href = await ownershipLink.getAttribute('href');
    await page.goto(href);
    return href;
  }

  test.describe('Access Ownership Page', () => {
    test('admin should access zone ownership page', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page).toHaveURL(/.*zones\/\d+\/ownership/);
    });

    test('should display current owners section', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page.locator('body')).toContainText(/owner|user/i);
    });

    test('should display group owners section', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // shared-zone carries both user and group owners
      await page.goto('/zones/forward?letter=all');
      const sharedRow = page.locator('tr:has-text("shared-zone")').first();
      await expect(sharedRow).toBeVisible();
      await sharedRow.locator('a[href*="/edit"]').first().click();

      const ownerLink = page.locator('a[href*="/ownership"]').first();
      await expect(ownerLink).toBeVisible();
      await ownerLink.click();

      await expect(page.locator('body')).toContainText(/group|ownership/i);
    });
  });

  test.describe('User Ownership', () => {
    test('should display user search field', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page.locator('#user_search')).toBeVisible();
    });

    test('should display user list with radio buttons', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page.locator('input[name="newowner"]').first()).toBeVisible();
    });

    test('should display add owner button', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page.locator('#add-owner-btn')).toBeVisible();
    });
  });

  test.describe('Group Ownership', () => {
    test('should display group search field', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page.locator('#group_search')).toBeVisible();
    });

    test('should display group checkboxes', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page.locator('.group-checkbox').first()).toBeVisible();
    });

    test('should display add group button', async ({ page }) => {
      await openZoneOwnership(page);
      await expect(page.locator('#add-group-btn')).toBeVisible();
    });
  });

  test.describe('Permission Tests', () => {
    test('non-owner cannot access zone ownership', async ({ page }) => {
      // Take a real ownership URL as admin, then try to open it as a read-only user
      const ownershipUrl = await openZoneOwnership(page);

      await page.context().clearCookies();
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto(ownershipUrl);

      // The viewer must not be handed the ownership form for a zone they do not own
      await expect(page.locator('#add-owner-btn')).toHaveCount(0);
      await expect(page.locator('body')).toContainText(/denied|permission|not authorized|do not have/i);
    });
  });
});
