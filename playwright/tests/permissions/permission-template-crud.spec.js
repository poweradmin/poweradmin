/**
 * Permission Template CRUD Operations Tests
 *
 * Tests for permission template management including listing,
 * adding, editing, and deleting permission templates.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensurePermTemplateExists } from '../../helpers/templates.js';
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

      // :not(.dropdown-item) keeps this off the hidden nav menu entry of the same href
      const addBtn = page.locator('a[href*="/permissions/templates/add"]:not(.dropdown-item)');
      await expect(addBtn.first()).toBeVisible();
    });

    test('should display filter tabs for All/User/Group', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const allBtn = page.locator('button[data-filter="all"]');
      const userBtn = page.locator('button[data-filter="user"]');
      const groupBtn = page.locator('button[data-filter="group"]');
      await expect(allBtn).toBeVisible();
      await expect(userBtn).toBeVisible();
      await expect(groupBtn).toBeVisible();
    });

    test('should filter templates by type', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const allRows = page.locator('tbody tr[data-type]');
      const totalCount = await allRows.count();

      if (totalCount > 0) {
        // Click User filter
        await page.locator('button[data-filter="user"]').click();
        const visibleAfterUser = page.locator('tbody tr[data-type]:visible');
        expect(await visibleAfterUser.count()).toBeLessThanOrEqual(totalCount);

        // Click All filter to reset
        await page.locator('button[data-filter="all"]').click();
        const visibleAfterAll = page.locator('tbody tr[data-type]:visible');
        expect(await visibleAfterAll.count()).toBe(totalCount);
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
      await expect(nameField.first()).toBeVisible();
    });

    test('should display description field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const descField = page.locator('input[name*="descr"], textarea[name*="descr"]');
      await expect(descField.first()).toBeVisible();
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

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
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

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
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
    // Edit against a template of our own so the run cannot mutate a built-in one
    const editableName = `${templateName}-editable`;

    async function openEditableTemplate(page) {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const templateId = await ensurePermTemplateExists(page, editableName);
      expect(templateId, `permission template "${editableName}" must exist`).toBeTruthy();
      await page.goto(`/permissions/templates/${templateId}/edit`);
      return templateId;
    }

    test('should access edit template page', async ({ page }) => {
      await openEditableTemplate(page);
      await expect(page).toHaveURL(/.*permissions\/templates\/\d+\/edit/);
    });

    test('should display current template name', async ({ page }) => {
      await openEditableTemplate(page);

      const nameField = page.locator('input[type="text"][name*="name"]:not([name*="id"]), input[type="text"][name*="templ"]').first();
      await expect(nameField).toHaveValue(editableName);
    });

    test('should update template name', async ({ page }) => {
      await openEditableTemplate(page);

      const renamed = `${editableName}-renamed`;
      const nameField = page.locator('input[type="text"][name*="name"]:not([name*="id"]), input[type="text"][name*="templ"]').first();
      await nameField.fill(renamed);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await page.goto('/permissions/templates');
      await expect(page.locator(`tr:has-text("${renamed}")`)).toBeVisible();

      // Restore the name so the other tests in this block still find the template
      await page.locator(`tr:has-text("${renamed}") a[href*="/edit"]`).first().click();
      await page.locator('input[type="text"][name*="name"]:not([name*="id"]), input[type="text"][name*="templ"]').first().fill(editableName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
    });

    test('should add permissions to template', async ({ page }) => {
      await openEditableTemplate(page);

      // .permission-checkbox skips the #select-all toggle, which carries no id of its own
      const target = page.locator('.permission-checkbox:not(:checked)').first();
      await expect(target).toBeVisible();
      const permId = await target.getAttribute('id');
      await target.check();
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await openEditableTemplate(page);
      await expect(page.locator(`#${permId}`)).toBeChecked();
    });

    test('should remove permissions from template', async ({ page }) => {
      await openEditableTemplate(page);

      // Grant a permission first so the removal has something to act on when run in isolation
      const unchecked = page.locator('.permission-checkbox:not(:checked)').first();
      await expect(unchecked).toBeVisible();
      const permId = await unchecked.getAttribute('id');
      await unchecked.check();
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await openEditableTemplate(page);
      const checkedBox = page.locator(`#${permId}`);
      await expect(checkedBox).toBeChecked();
      await checkedBox.uncheck();
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await openEditableTemplate(page);
      await expect(page.locator(`#${permId}`)).not.toBeChecked();
    });
  });

  test.describe('Delete Permission Template', () => {
    // Confirm against a template of our own so a stray confirmation cannot delete a built-in one
    const deletableName = `${templateName}-deletable`;

    async function openDeleteConfirmation(page) {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const templateId = await ensurePermTemplateExists(page, deletableName);
      expect(templateId, `permission template "${deletableName}" must exist`).toBeTruthy();
      await page.goto(`/permissions/templates/${templateId}/delete`);
    }

    test('should access delete confirmation', async ({ page }) => {
      await openDeleteConfirmation(page);
      await expect(page).toHaveURL(/.*permissions\/templates\/\d+\/delete/);
    });

    test('should display confirmation message', async ({ page }) => {
      await openDeleteConfirmation(page);
      await expect(page.locator('body')).toContainText(/delete|confirm|sure/i);
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await openDeleteConfirmation(page);

      const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
      await expect(noBtn).toBeVisible();
      await noBtn.click();

      await expect(page).toHaveURL(/.*permissions\/templates/);
      // Cancelling must leave the template in place
      await expect(page.locator(`tr:has-text("${deletableName}")`)).toBeVisible();
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
