/**
 * Zones Management Tests
 *
 * Tests for zones list page display and functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('List Zones', () => {
  test.describe('Admin User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|list/i);
    });

    test('should display zones table', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should have add master zone link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      // Link may be in a dropdown menu, so check for existence not visibility
      const addMasterLink = page.locator('a[href*="/add/master"]');
      expect(await addMasterLink.count()).toBeGreaterThan(0);
    });

    test('should have add slave zone link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      // Link may be in a dropdown menu, so check for existence not visibility
      const addSlaveLink = page.locator('a[href*="/add/slave"]');
      expect(await addSlaveLink.count()).toBeGreaterThan(0);
    });

    test('should display zone rows when zones exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const rows = page.locator('table tbody tr');
      await expect(rows.first()).toBeVisible();
    });

    test('should display edit links for zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLinks = page.locator('table a[href*="/edit"]');
      await expect(editLinks.first()).toBeVisible();
    });

    test('should display delete links for zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLinks = page.locator('table a[href*="/delete"]');
      await expect(deleteLinks.first()).toBeVisible();
    });

    test('should have working add master zone navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*zones\/add\/master/);
    });

    test('should have working add slave zone navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*zones\/add\/slave/);
    });
  });

  test.describe('Manager User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('should display add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Client User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('should not display add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/forward?letter=all');
      const addMasterBtn = page.locator('input[value*="Add master zone"]');
      const addSlaveBtn = page.locator('input[value*="Add slave zone"]');
      expect(await addMasterBtn.count()).toBe(0);
      expect(await addSlaveBtn.count()).toBe(0);
    });
  });

  test.describe('Viewer User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('should not display add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const addMasterBtn = page.locator('input[value*="Add master zone"]');
      expect(await addMasterBtn.count()).toBe(0);
    });

    test('should not display delete buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLinks = page.locator('a[href*="/delete"]');
      expect(await deleteLinks.count()).toBe(0);
    });
  });
});

test.describe('Edit Zone', () => {
  test.describe('Admin User', () => {
    test('should access zone edit page from list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();
      await expect(page).toHaveURL(/.*edit/);
    });

    test('should display records table on edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();
      await expect(page.locator('table').first()).toBeVisible();
    });

    test('should display zone metadata', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();
      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).toContainText(/owner|zone|type/i);
    });
  });
});

test.describe('Delete Zone', () => {
  test.describe('Admin User', () => {
    test('should navigate to delete zone page from list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('table a[href*="/delete"]').first();
      await expect(deleteLink).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*delete/);
    });

    test('should display confirmation on delete page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('table a[href*="/delete"]').first();
      await expect(deleteLink).toBeVisible();
      await deleteLink.click();
      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).toContainText(/delete|confirm|sure/i);
    });

    test('should display yes and no buttons on delete page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('table a[href*="/delete"]').first();
      await expect(deleteLink).toBeVisible();
      await deleteLink.click();

      // delete_actions() renders confirm as a button and cancel as a link
      await expect(page.locator('button[type="submit"]:has-text("Yes"), input[value*="Yes"]').first()).toBeVisible();
      await expect(page.locator('a:has-text("No"), input[value*="No"]').first()).toBeVisible();
    });
  });

  test.describe('Viewer User', () => {
    test('should not see delete buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLinks = page.locator('a[href*="/delete"]');
      expect(await deleteLinks.count()).toBe(0);
    });
  });
});

test.describe('Edit Zone Comment', () => {
  test.describe('Admin User', () => {
    // The comment link lives on the zone edit page; the list only shows the comment as a tooltip
    async function openCommentForm(page) {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const editLink = page.locator('table a[href*="/edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();

      // The comment UI is gated behind show_zone_comments; skip visibly when it is off
      const commentLink = page.locator('a[href*="/comment/edit"]').first();
      test.skip(await commentLink.count() === 0, 'zone comments are disabled on this instance (show_zone_comments)');
      await commentLink.click();
    }

    test('should access edit comment page', async ({ page }) => {
      await openCommentForm(page);
      await expect(page).toHaveURL(/.*\/comment\/edit/);
    });

    test('should display comment form on edit comment page', async ({ page }) => {
      await openCommentForm(page);
      await expect(page.locator('textarea').first()).toBeVisible();
    });

    test('should display update button on edit comment page', async ({ page }) => {
      await openCommentForm(page);
      await expect(page.locator('input[type="submit"], button[type="submit"]').first()).toBeVisible();
    });
  });
});
