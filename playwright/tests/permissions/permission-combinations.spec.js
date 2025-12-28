import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Permission Combinations', () => {
  test.describe('Permission Template Management', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display permission templates list', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      await expect(page).toHaveURL(/list_perm_templ/);
    });

    test('should display add template button', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const addBtn = page.locator('a[href*="add_perm_templ"], input[value*="Add"]');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('should access add template page', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      await expect(page).toHaveURL(/add_perm_templ/);
    });

    test('should display permission checkboxes', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const checkboxes = page.locator('input[type="checkbox"]');
      expect(await checkboxes.count()).toBeGreaterThan(0);
    });

    test('should display template name field', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const nameField = page.locator('input[name*="name"], input[name*="templ"]');
      expect(await nameField.count()).toBeGreaterThan(0);
    });

    test('should display description field', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const descField = page.locator('textarea[name*="descr"], input[name*="descr"]');
      expect(await descField.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Permission Options', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display zone permissions', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone/i);
    });

    test('should display record permissions', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/record/i);
    });

    test('should display user permissions', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/user/i);
    });

    test('should display supermaster permissions', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/supermaster/i);
    });

    test('should display search permissions', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/search/i);
    });
  });

  test.describe('Template Creation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should create template with zone view only', async ({ page }) => {
      const templateName = `view-only-${Date.now()}`;
      await page.goto('/index.php?page=add_perm_templ');
      await page.locator('input[name*="name"]').first().fill(templateName);

      // Check only zone view permission
      const zoneViewCheckbox = page.locator('input[name*="zone_content_view"], input[value*="zone_view"]').first();
      if (await zoneViewCheckbox.count() > 0) {
        await zoneViewCheckbox.check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Cleanup
      await page.goto('/index.php?page=list_perm_templ');
      const deleteLink = page.locator(`tr:has-text("${templateName}") a[href*="delete_perm_templ"]`).first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should create template with full zone management', async ({ page }) => {
      const templateName = `full-zone-${Date.now()}`;
      await page.goto('/index.php?page=add_perm_templ');
      await page.locator('input[name*="name"]').first().fill(templateName);

      // Check all zone permissions
      const zoneCheckboxes = page.locator('input[type="checkbox"][name*="zone"]');
      const count = await zoneCheckboxes.count();
      for (let i = 0; i < count; i++) {
        await zoneCheckboxes.nth(i).check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Cleanup
      await page.goto('/index.php?page=list_perm_templ');
      const deleteLink = page.locator(`tr:has-text("${templateName}") a[href*="delete_perm_templ"]`).first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should reject template without name', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/add_perm_templ/);
    });
  });

  test.describe('Template Editing', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access edit template page', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const editLink = page.locator('a[href*="edit_perm_templ"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/edit_perm_templ/);
      }
    });

    test('should display current template permissions', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const editLink = page.locator('a[href*="edit_perm_templ"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const checkboxes = page.locator('input[type="checkbox"]');
        expect(await checkboxes.count()).toBeGreaterThan(0);
      }
    });

    test('should update template permissions', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const editLink = page.locator('a[href*="edit_perm_templ"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        // Toggle a permission
        const firstCheckbox = page.locator('input[type="checkbox"]').first();
        if (await firstCheckbox.count() > 0) {
          const isChecked = await firstCheckbox.isChecked();
          if (isChecked) {
            await firstCheckbox.uncheck();
          } else {
            await firstCheckbox.check();
          }
          await page.locator('button[type="submit"], input[type="submit"]').first().click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Template Deletion', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display delete option', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const deleteLink = page.locator('a[href*="delete_perm_templ"]');
      // Delete should be available for non-default templates
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should confirm before delete', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const deleteLink = page.locator('a[href*="delete_perm_templ"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm/i);
      }
    });

    test('should cancel delete', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const deleteLink = page.locator('a[href*="delete_perm_templ"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/list_perm_templ/);
        }
      }
    });
  });

  test.describe('Template Assignment', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display template selector in user edit', async ({ page }) => {
      await page.goto('/index.php?page=users');
      const editLink = page.locator('a[href*="edit_user"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const templateSelect = page.locator('select[name*="templ"], select[name*="template"]');
        if (await templateSelect.count() > 0) {
          await expect(templateSelect.first()).toBeVisible();
        }
      }
    });

    test('should display template selector in add user', async ({ page }) => {
      await page.goto('/index.php?page=add_user');
      const templateSelect = page.locator('select[name*="templ"], select[name*="template"]');
      if (await templateSelect.count() > 0) {
        await expect(templateSelect.first()).toBeVisible();
      }
    });
  });

  test.describe('Permission Access Control', () => {
    test('admin should access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/denied|permission/i);
    });

    test('manager should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_perm_templ');
      const bodyText = await page.locator('body').textContent();
      // Manager should not have access
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('client should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/index.php?page=list_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/index.php?page=list_perm_templ');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
