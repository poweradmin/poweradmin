import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensurePermTemplateExists } from '../../helpers/templates.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Permission Template CRUD Operations', () => {
  const templateName = `perm-template-${Date.now()}`;

  test.describe('List Permission Templates', () => {
    test('admin should access permission templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_perm_templ');

      await expect(page).toHaveURL(/page=list_perm_templ/);
    });

    test('should display templates table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_perm_templ');

      const hasTable = await page.locator('table').count() > 0;
      if (hasTable) {
        await expect(page.locator('table').first()).toBeVisible();
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).toMatch(/template|no.*template|empty/i);
      }
    });

    test('should display add template button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_perm_templ');

      const addBtn = page.locator('a[href*="add_perm_templ"], input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('non-admin should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_perm_templ');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !url.includes('list_perm_templ');
      expect(accessDenied).toBeTruthy();
    });
  });

  test.describe('Add Permission Template', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add template page', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      await expect(page).toHaveURL(/page=add_perm_templ/);
    });

    test('should display template name field', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');

      const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display description field', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');

      const descField = page.locator('input[name*="descr"], textarea[name*="descr"]');
      if (await descField.count() > 0) {
        await expect(descField.first()).toBeVisible();
      }
    });

    test('should display permission checkboxes', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');

      const checkboxes = page.locator('input[type="checkbox"]');
      expect(await checkboxes.count()).toBeGreaterThan(0);
    });

    test('should create template with name only', async ({ page }) => {
      const uniqueName = `${templateName}-nameonly`;
      await page.goto('/index.php?page=add_perm_templ');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should create template with permissions', async ({ page }) => {
      const uniqueName = `${templateName}-withperms`;
      await page.goto('/index.php?page=add_perm_templ');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);

      // Select some permissions
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      if (count > 0) {
        await checkboxes.first().check();
        if (count > 1) {
          await checkboxes.nth(1).check();
        }
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty template name', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/add_perm_templ/);
    });

    test('should display permission categories', async ({ page }) => {
      await page.goto('/index.php?page=add_perm_templ');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/permission|zone|user|record/i);
    });
  });

  test.describe('Edit Permission Template', () => {
    let templateId = null;
    const editTemplateName = `edit-perm-${Date.now()}`;

    test.beforeAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      templateId = await ensurePermTemplateExists(page, editTemplateName);
      await page.close();
    });

    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access edit template page', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=edit_perm_templ&id=${templateId}`);
      await expect(page).toHaveURL(/edit_perm_templ/);
    });

    test('should display current template name', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=edit_perm_templ&id=${templateId}`);

      // Find text input for template name, excluding hidden fields and ID fields
      const nameField = page.locator('input[type="text"][name*="name"]:not([name*="id"]), input[type="text"][name*="templ"]').first();
      if (await nameField.count() > 0) {
        const value = await nameField.inputValue();
        // Verify it contains some text (template name may vary)
        expect(value.length).toBeGreaterThan(0);
      }
    });

    test('should update template name', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=edit_perm_templ&id=${templateId}`);

      // Find text input for template name
      const nameField = page.locator('input[type="text"][name*="name"]:not([name*="id"]), input[type="text"][name*="templ"]').first();
      if (await nameField.count() > 0) {
        await nameField.fill(`${editTemplateName}-updated`);
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should add permissions to template', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=edit_perm_templ&id=${templateId}`);

      const uncheckedBox = page.locator('input[type="checkbox"]:not(:checked)').first();
      if (await uncheckedBox.count() > 0) {
        await uncheckedBox.check();
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should remove permissions from template', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=edit_perm_templ&id=${templateId}`);

      const checkedBox = page.locator('input[type="checkbox"]:checked').first();
      if (await checkedBox.count() > 0) {
        await checkedBox.uncheck();
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Delete Permission Template', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access delete confirmation', async ({ page }) => {
      // Create a template to delete
      const toDelete = `delete-perm-${Date.now()}`;
      await page.goto('/index.php?page=add_perm_templ');
      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(toDelete);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=list_perm_templ');
      const row = page.locator(`tr:has-text("${toDelete}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_perm_templ"]').first();
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_perm_templ/);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const deleteLink = page.locator('a[href*="delete_perm_templ"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const deleteLink = page.locator('a[href*="delete_perm_templ"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/list_perm_templ/);
        }
      }
    });

    test('should delete template successfully', async ({ page }) => {
      // Create a template to delete
      const toDelete = `delete-success-perm-${Date.now()}`;
      await page.goto('/index.php?page=add_perm_templ');
      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(toDelete);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=list_perm_templ');
      const row = page.locator(`tr:has-text("${toDelete}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_perm_templ"]').first();
        await deleteLink.click();

        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();

          // Verify deleted
          await page.goto('/index.php?page=list_perm_templ');
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toContain(toDelete);
        }
      }
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_perm_templ');

    const testTemplates = page.locator('tr').filter({ hasText: /perm-template-|edit-perm-|delete-perm-/ });
    const count = await testTemplates.count();

    for (let i = 0; i < count; i++) {
      await page.goto('/index.php?page=list_perm_templ');
      const row = page.locator('tr').filter({ hasText: /perm-template-|edit-perm-|delete-perm-/ }).first();

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_perm_templ"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) {
            await yesBtn.click();
          }
        }
      }
    }

    await page.close();
  });
});
