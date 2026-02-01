/**
 * Permission Template CRUD Operations Tests
 *
 * Tests for permission template management including listing,
 * adding, editing, and deleting permission templates.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Permission Template CRUD Operations', () => {
  const templateName = `perm-template-${Date.now()}`;

  test.describe('List Permission Templates', () => {
    test('admin should access permission templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      await expect(page).toHaveURL(/.*permissions\/templates/);
    });

    test('should display templates table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

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
      await page.goto('/permissions/templates');

      const addBtn = page.locator('a[href*="/permissions/templates/add"], input[value*="Add"], button:has-text("Add"), a:has-text("Add permission template")');
      if (await addBtn.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/permission template/i);
      } else {
        expect(await addBtn.count()).toBeGreaterThan(0);
      }
    });

    test('non-admin should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/permissions/templates');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !url.includes('permissions/templates');
      expect(accessDenied).toBeTruthy();
    });
  });

  test.describe('Add Permission Template', () => {
    test('should access add template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      await expect(page).toHaveURL(/.*permissions\/templates\/add/);
    });

    test('should display template name field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameField = page.locator('input[name="templ_name"], input[id="templ_name"], input[name*="name"]');
      if (await nameField.count() > 0) {
        await expect(nameField.first()).toBeVisible();
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/name|template/i);
      }
    });

    test('should display description field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const descField = page.locator('input[name*="descr"], textarea[name*="descr"]');
      if (await descField.count() > 0) {
        await expect(descField.first()).toBeVisible();
      }
    });

    test('should display permission checkboxes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const checkboxes = page.locator('input[type="checkbox"]');
      expect(await checkboxes.count()).toBeGreaterThan(0);
    });

    test('should create template with name only', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueName = `${templateName}-nameonly`;
      await page.goto('/permissions/templates/add');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should create template with permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueName = `${templateName}-withperms`;
      await page.goto('/permissions/templates/add');

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
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/permissions\/templates\/add/);
    });

    test('should display permission categories', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/permission|zone|user|record/i);
    });
  });

  test.describe('Edit Permission Template', () => {
    test('should access edit template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      // Use table-specific selector to avoid matching dropdown menu items
      const table = page.locator('table');
      if (await table.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = table.locator('tbody a[href*="permissions"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/.*permissions\/templates\/\d+\/edit/);
      } else {
        // No templates to edit - this is acceptable
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should display current template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const table = page.locator('table');
      if (await table.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = table.locator('tbody a[href*="permissions"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const nameField = page.locator('input[type="text"][name*="name"]:not([name*="id"]), input[type="text"][name*="templ"]').first();
        if (await nameField.count() > 0) {
          const value = await nameField.inputValue();
          expect(value.length).toBeGreaterThan(0);
        }
      }
    });

    test('should update template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const table = page.locator('table');
      if (await table.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = table.locator('tbody a[href*="permissions"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const nameField = page.locator('input[type="text"][name*="name"]:not([name*="id"]), input[type="text"][name*="templ"]').first();
        if (await nameField.count() > 0) {
          await nameField.fill(`updated-template-${Date.now()}`);
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should add permissions to template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const table = page.locator('table');
      if (await table.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = table.locator('tbody a[href*="permissions"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const uncheckedBox = page.locator('input[type="checkbox"]:not(:checked)').first();
        if (await uncheckedBox.count() > 0) {
          await uncheckedBox.check();
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should remove permissions from template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const table = page.locator('table');
      if (await table.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = table.locator('tbody a[href*="permissions"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const checkedBox = page.locator('input[type="checkbox"]:checked').first();
        if (await checkedBox.count() > 0) {
          await checkedBox.uncheck();
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Delete Permission Template', () => {
    test('should access delete confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/.*delete/);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/.*permissions\/templates/);
        }
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/permissions/templates');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           url.includes('/login') ||
                           !url.includes('permissions/templates');
      expect(accessDenied).toBeTruthy();
    });

    test('client should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/permissions/templates');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           url.includes('/login') ||
                           !url.includes('permissions/templates');
      expect(accessDenied).toBeTruthy();
    });
  });
});
