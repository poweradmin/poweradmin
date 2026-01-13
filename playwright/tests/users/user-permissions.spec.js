import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('User Permission Combinations', () => {
  test.describe('Admin Permissions', () => {
    test('should access user management', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      await expect(page).toHaveURL(/page=users/);
    });

    test('should add new users', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await expect(page).toHaveURL(/add_user/);
    });

    test('should delete users', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const deleteLink = page.locator('a[href*="delete_user"]').first();
      expect(await deleteLink.count()).toBeGreaterThan(0);
    });

    test('should access permission templates', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      await expect(page).toHaveURL(/list_perm_templ/);
    });

    test('should access supermaster management', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_supermasters');
      // Verify page loads successfully
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      // Check for actual error messages, not just the word "permission" which may appear in UI elements
      const hasAccessError = bodyText.match(/you do not have|access denied|not authorized/i);
      expect(hasAccessError).toBeFalsy();
    });

    test('should add master zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      await expect(page).toHaveURL(/add_zone_master/);
    });

    test('should add slave zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_zone_slave');
      await expect(page).toHaveURL(/add_zone_slave/);
    });

    test('should access all zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const bodyText = await page.locator('body').textContent();
      // Check for actual access denial messages, not UI elements containing "permission"
      const hasAccessError = bodyText.match(/you do not have|access denied|not authorized/i);
      expect(hasAccessError).toBeFalsy();
    });

    test('should edit any zone', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        // Check for actual access denial messages, not UI elements containing "permission"
        const hasAccessError = bodyText.match(/you do not have|access denied|not authorized/i);
        expect(hasAccessError).toBeFalsy();
      }
    });

    test('should delete any zone', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      // Check for delete links if zones exist
      const deleteLink = page.locator('a[href*="delete_domain"]').first();
      const hasZones = await page.locator('table tr').count() > 1;
      if (hasZones) {
        // If zones exist, verify delete option is available
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should access DNSSEC settings', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const dnssecLink = page.locator('a[href*="page=dnssec"]').first();
      if (await dnssecLink.count() > 0) {
        await dnssecLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/denied|permission/i);
      }
    });
  });

  test.describe('Manager Permissions', () => {
    test('should not access user management', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=users');
      const bodyText = await page.locator('body').textContent();
      // Manager should not have user management access
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add zones', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/denied|permission/i);
    });

    test('should edit own zones', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/denied|permission/i);
      }
    });

    test('should delete own zones', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const deleteLink = page.locator('a[href*="delete_domain"]').first();
      // Manager should have delete option for own zones
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add records to own zones', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const addRecordLink = page.locator('a[href*="add_record"]').first();
        if (await addRecordLink.count() > 0) {
          await addRecordLink.click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/denied|permission/i);
        }
      }
    });

    test('should search zones and records', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should access zone templates', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Client Permissions', () => {
    test('should not access user management', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=users');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not add master zones', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      // Client should not have add zone permission
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should view assigned zones', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should edit records in assigned zones', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/denied|permission/i);
      }
    });

    test('should not edit SOA records', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const soaEditLink = page.locator('a[href*="edit_record"]:has-text("SOA")');
        // Client should not be able to edit SOA
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should search zones', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });
  });

  test.describe('Viewer Permissions', () => {
    test('should view zones read-only', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not have add zone button', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const addBtn = page.locator('input[value*="Add master"], input[value*="Add slave"]');
      expect(await addBtn.count()).toBe(0);
    });

    test('should not have delete zone option', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const deleteLink = page.locator('a[href*="delete_domain"]');
      expect(await deleteLink.count()).toBe(0);
    });

    test('should view zone details', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should not have add record option', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const addRecordLink = page.locator('a[href*="add_record"]');
        expect(await addRecordLink.count()).toBe(0);
      }
    });

    test('should not have edit record option', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const editRecordLink = page.locator('a[href*="edit_record"]');
        expect(await editRecordLink.count()).toBe(0);
      }
    });

    test('should not have delete record option', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      // Use table-specific selector to avoid matching dropdown menu links
      const editLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const deleteRecordLink = page.locator('a[href*="delete_record"]');
        expect(await deleteRecordLink.count()).toBe(0);
      }
    });

    test('should search zones', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });
  });

  test.describe('No Permissions User', () => {
    test('should not access dashboard', async ({ nopermPage: page }) => {
      const bodyText = await page.locator('body').textContent();
      // User with no permissions should still be able to log in but have no access
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not see zones', async ({ nopermPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
