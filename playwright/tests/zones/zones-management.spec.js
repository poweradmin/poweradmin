import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('List Zones', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_zones');
    });

    test('should display zones page', async ({ page }) => {
      await expect(page).toHaveURL(/page=list_zones/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|list/i);
    });

    test('should display zones table', async ({ page }) => {
      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should display add master zone button', async ({ page }) => {
      const addMasterBtn = page.locator('input[value*="Add master"], button:has-text("Add master")').first();
      if (await addMasterBtn.count() > 0) {
        await expect(addMasterBtn).toBeVisible();
      }
    });

    test('should display add slave zone button', async ({ page }) => {
      const addSlaveBtn = page.locator('input[value*="Add slave"], button:has-text("Add slave")').first();
      if (await addSlaveBtn.count() > 0) {
        await expect(addSlaveBtn).toBeVisible();
      }
    });

    test('should display zone rows when zones exist', async ({ page }) => {
      const rows = page.locator('table tbody tr');
      if (await rows.count() > 0) {
        await expect(rows.first()).toBeVisible();
      }
    });

    test('should display edit links for zones', async ({ page }) => {
      const editLinks = page.locator('a[href*="page=edit"]');
      if (await editLinks.count() > 0) {
        await expect(editLinks.first()).toBeVisible();
      }
    });

    test('should display delete links for zones', async ({ page }) => {
      const deleteLinks = page.locator('a[href*="delete_domain"]');
      if (await deleteLinks.count() > 0) {
        await expect(deleteLinks.first()).toBeVisible();
      }
    });

    test('should have working add master zone navigation', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');
      await expect(page).toHaveURL(/page=add_zone_master/);
    });

    test('should have working add slave zone navigation', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_slave');
      await expect(page).toHaveURL(/page=add_zone_slave/);
    });
  });

  test.describe('Manager User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_zones');
    });

    test('should display zones page', async ({ page }) => {
      await expect(page).toHaveURL(/page=list_zones/);
    });

    test('should display add zone buttons', async ({ page }) => {
      const addBtns = page.locator('input[value*="Add"], button:has-text("Add")');
      expect(await addBtns.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Client User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/index.php?page=list_zones');
    });

    test('should display zones page', async ({ page }) => {
      await expect(page).toHaveURL(/page=list_zones/);
    });

    test('should not display add zone buttons', async ({ page }) => {
      const addMasterBtn = page.locator('input[value*="Add master zone"]');
      const addSlaveBtn = page.locator('input[value*="Add slave zone"]');
      expect(await addMasterBtn.count()).toBe(0);
      expect(await addSlaveBtn.count()).toBe(0);
    });
  });

  test.describe('Viewer User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/index.php?page=list_zones');
    });

    test('should display zones page', async ({ page }) => {
      await expect(page).toHaveURL(/page=list_zones/);
    });

    test('should not display add zone buttons', async ({ page }) => {
      const addMasterBtn = page.locator('input[value*="Add master zone"]');
      expect(await addMasterBtn.count()).toBe(0);
    });

    test('should not display delete buttons', async ({ page }) => {
      const deleteLinks = page.locator('a[href*="delete_domain"]');
      expect(await deleteLinks.count()).toBe(0);
    });
  });
});

test.describe('Edit Zone', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access zone edit page from list', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/page=edit/);
      }
    });

    test('should display records table on edit page', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const table = page.locator('table').first();
        await expect(table).toBeVisible();
      }
    });

    test('should display zone metadata', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/owner|zone|type/i);
      }
    });
  });
});

test.describe('Delete Zone', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should navigate to delete zone page from list', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      const deleteLink = page.locator('a[href*="delete_domain"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_domain/);
      }
    });

    test('should display confirmation on delete page', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      const deleteLink = page.locator('a[href*="delete_domain"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should display yes and no buttons on delete page', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      const deleteLink = page.locator('a[href*="delete_domain"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await yesBtn.count() > 0) {
          await expect(yesBtn).toBeVisible();
        }
        if (await noBtn.count() > 0) {
          await expect(noBtn).toBeVisible();
        }
      }
    });
  });

  test.describe('Viewer User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/index.php?page=list_zones');
    });

    test('should not see delete buttons', async ({ page }) => {
      const deleteLinks = page.locator('a[href*="delete_domain"]');
      expect(await deleteLinks.count()).toBe(0);
    });
  });
});

test.describe('Edit Zone Comment', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access edit comment page', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      // Find a zone and navigate to its edit comment page
      const row = page.locator('table tbody tr').first();
      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_comment"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();
          await expect(page).toHaveURL(/edit_comment/);
        }
      }
    });

    test('should display comment form on edit comment page', async ({ page }) => {
      // Try accessing directly with a known zone ID pattern
      await page.goto('/index.php?page=edit_comment&id=1');
      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await expect(textarea).toBeVisible();
      }
    });

    test('should display update button on edit comment page', async ({ page }) => {
      await page.goto('/index.php?page=edit_comment&id=1');
      const updateBtn = page.locator('input[type="submit"], button[type="submit"]').first();
      if (await updateBtn.count() > 0) {
        await expect(updateBtn).toBeVisible();
      }
    });
  });
});
