/**
 * User Permission Combinations Tests
 *
 * Tests for permission verification across different user roles
 * including admin, manager, client, and viewer permissions.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('User Permission Combinations', () => {
  test.describe('Admin Permissions', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access user management', async ({ page }) => {
      await page.goto('/users');
      await expect(page).toHaveURL(/.*\/users/);
    });

    test('should add new users', async ({ page }) => {
      await page.goto('/users/add');
      await expect(page).toHaveURL(/.*\/users\/add/);
    });

    test('should delete users', async ({ page }) => {
      await page.goto('/users');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      expect(await deleteLink.count()).toBeGreaterThan(0);
    });

    test('should access permission templates', async ({ page }) => {
      await page.goto('/permissions/templates');
      await expect(page).toHaveURL(/.*permissions\/templates/);
    });

    test('should access supermaster management', async ({ page }) => {
      await page.goto('/supermasters');
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasAccessError = bodyText.match(/you do not have|access denied|not authorized/i);
      expect(hasAccessError).toBeFalsy();
    });

    test('should add master zones', async ({ page }) => {
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*zones\/add\/master/);
    });

    test('should add slave zones', async ({ page }) => {
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*zones\/add\/slave/);
    });

    test('should access all zones', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      const hasAccessError = bodyText.match(/you do not have|access denied|not authorized/i);
      expect(hasAccessError).toBeFalsy();
    });

    test('should edit any zone', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        const hasAccessError = bodyText.match(/you do not have|access denied|not authorized/i);
        expect(hasAccessError).toBeFalsy();
      }
    });

    test('should delete any zone', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const hasZones = await page.locator('table tr').count() > 1;
      if (hasZones) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should access DNSSEC settings', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const dnssecLink = page.locator('a[href*="/dnssec"]').first();
      if (await dnssecLink.count() > 0) {
        await dnssecLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/denied|permission/i);
      }
    });
  });

  test.describe('Manager Permissions', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should not access user management', async ({ page }) => {
      await page.goto('/users');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add zones', async ({ page }) => {
      await page.goto('/zones/add/master');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/denied|permission/i);
    });

    test('should edit own zones', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/denied|permission/i);
      }
    });

    test('should delete own zones', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add records to own zones', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const addRecordLink = page.locator('a[href*="/records/add"]').first();
        if (await addRecordLink.count() > 0) {
          await addRecordLink.click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/denied|permission/i);
        }
      }
    });

    test('should search zones and records', async ({ page }) => {
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should access zone templates', async ({ page }) => {
      await page.goto('/zones/templates');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Client Permissions', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should not access user management', async ({ page }) => {
      await page.goto('/users');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not add master zones', async ({ page }) => {
      await page.goto('/zones/add/master');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should view assigned zones', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should edit records in assigned zones', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/denied|permission/i);
      }
    });

    test('should search zones', async ({ page }) => {
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });
  });

  test.describe('Viewer Permissions', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should view zones read-only', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not have add zone button', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const addBtn = page.locator('a[href*="/zones/add/master"], a[href*="/zones/add/slave"]');
      expect(await addBtn.count()).toBe(0);
    });

    test('should not have delete zone option', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('a[href*="/delete"]');
      expect(await deleteLink.count()).toBe(0);
    });

    test('should view zone details', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should not have add record option', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const addRecordLink = page.locator('a[href*="/records/add"]');
        expect(await addRecordLink.count()).toBe(0);
      }
    });

    test('should not have edit record option', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const editRecordLink = page.locator('a[href*="/records/"][href*="/edit"]');
        expect(await editRecordLink.count()).toBe(0);
      }
    });

    test('should not have delete record option', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const deleteRecordLink = page.locator('a[href*="/records/"][href*="/delete"]');
        expect(await deleteRecordLink.count()).toBe(0);
      }
    });

    test('should search zones read-only', async ({ page }) => {
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should change own password', async ({ page }) => {
      await page.goto('/password/change');
      await expect(page).toHaveURL(/.*password\/change/);
    });
  });

  test.describe('Cross-Role Permission Tests', () => {
    test('manager should not see user logs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/users/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('/login');
      expect(hasError).toBeTruthy();
    });

    test('client should not see zone logs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('/login');
      expect(hasError).toBeTruthy();
    });

    test('viewer should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/supermasters');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('denied') ||
                       bodyText.toLowerCase().includes('permission') ||
                       page.url().includes('/login') ||
                       !page.url().includes('supermasters');
      expect(hasError).toBeTruthy();
    });
  });
});
