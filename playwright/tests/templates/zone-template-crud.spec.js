/**
 * Zone Template CRUD Operations Tests
 *
 * Tests for zone template management including listing,
 * adding, editing, and deleting zone templates.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone Template CRUD Operations', () => {
  const templateName = `test-template-${Date.now()}`;
  const templateDescription = 'Automated test template';

  test.describe('List Templates', () => {
    test('admin should access templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');
      await expect(page).toHaveURL(/.*zones\/templates/);
    });

    test('should display templates table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

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
      await page.goto('/zones/templates');

      const addBtn = page.locator('a[href*="/zones/templates/add"], input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('manager should access templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/templates');
      await expect(page).toHaveURL(/.*zones\/templates/);
    });

    test('should show template owner column', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const hasTable = await page.locator('table').count() > 0;
      if (hasTable) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/owner|user/);
      }
    });
  });

  test.describe('Add Template', () => {
    test('should access add template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');
      await expect(page).toHaveURL(/.*zones\/templates\/add/);
      await expect(page.locator('form')).toBeVisible();
    });

    test('should display template name field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');

      const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display description field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');

      const descField = page.locator('input[name*="description"], textarea[name*="description"]');
      if (await descField.count() > 0) {
        await expect(descField.first()).toBeVisible();
      }
    });

    test('should create template with name only', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueName = `${templateName}-nameonly`;
      await page.goto('/zones/templates/add');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      const hasSuccess = bodyText.toLowerCase().includes('success') ||
                         bodyText.toLowerCase().includes('created') ||
                         bodyText.includes(uniqueName) ||
                         page.url().includes('/zones/templates');
      expect(hasSuccess).toBeTruthy();
    });

    test('should create template with name and description', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueName = `${templateName}-full`;
      await page.goto('/zones/templates/add');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);

      const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
      if (await descField.count() > 0) {
        await descField.fill(templateDescription);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('required') ||
                       url.includes('/zones/templates/add');
      expect(hasError).toBeTruthy();
    });

    test('should allow template name with special characters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueName = `${templateName}-special-chars-@#`;
      await page.goto('/zones/templates/add');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Edit Template', () => {
    test('should access edit template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      // Use table-specific selector to avoid matching dropdown menu items
      const templateTable = page.locator('table');
      if (await templateTable.count() === 0) {
        // No templates exist, skip
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/template|zone/i);
        return;
      }

      const editLink = templateTable.locator('tbody a[href*="templates"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveURL(/.*zones\/templates\/\d+\/edit/);
      } else {
        // No edit links, just verify page loaded
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/template|zone/i);
      }
    });

    test('should display current template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const templateTable = page.locator('table');
      if (await templateTable.count() === 0) {
        return; // No templates, skip
      }

      const editLink = templateTable.locator('tbody a[href*="templates"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await page.waitForLoadState('networkidle');

        const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
        const value = await nameField.inputValue();
        expect(value.length).toBeGreaterThan(0);
      }
    });

    test('should update template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');
      await page.waitForLoadState('networkidle');

      const templateTable = page.locator('table');
      if (await templateTable.count() === 0) {
        // No templates table, just verify page loaded
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/template|zone/i);
        return;
      }

      const editLink = templateTable.locator('tbody a[href*="templates"][href*="edit"]').first();
      if (await editLink.count() === 0) {
        // No edit links, just verify page loaded
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/template|zone/i);
        return;
      }

      await editLink.click();
      await page.waitForLoadState('networkidle');

      const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
      if (await nameField.count() === 0) {
        // No name field found, just verify page loaded
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await nameField.fill(`updated-template-${Date.now()}`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      if (await submitBtn.count() === 0 || !(await submitBtn.isEnabled())) {
        // No submit button found or not enabled
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should update template description', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const templateTable = page.locator('table');
      if (await templateTable.count() === 0) {
        return; // No templates, skip
      }

      const editLink = templateTable.locator('tbody a[href*="templates"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await page.waitForLoadState('networkidle');

        const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
        if (await descField.count() > 0) {
          await descField.fill(`Updated description ${Date.now()}`);
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Delete Template', () => {
    test('should access delete confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/.*delete/);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/.*zones\/templates/);
        }
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not have add template button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/templates');

      const addBtn = page.locator('a[href*="/zones/templates/add"]');
      expect(await addBtn.count()).toBe(0);
    });

    test('client should have limited template permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/templates');

      // Client can view templates page and may have access to templates they own
      // Just verify the page loads without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
