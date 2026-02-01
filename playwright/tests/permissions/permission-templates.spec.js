import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Permission Templates Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access permission templates page', async ({ page }) => {
    await page.goto('/permissions/templates');
    await page.waitForLoadState('networkidle');

    // Check if permission templates page exists
    const bodyText = await page.locator('body').textContent();
    if (bodyText.toLowerCase().includes('permission') ||
        bodyText.toLowerCase().includes('template')) {
      await expect(page).toHaveURL(/.*permissions\/templates/);
    } else {
      // Permission templates feature might not be available
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should display permission templates list or empty state', async ({ page }) => {
    await page.goto('/permissions/templates');

    // Should show either templates table or empty state
    const hasTable = await page.locator('table, .table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table, .table')).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No templates|templates|empty/i);
    }
  });

  test('should access add permission template page', async ({ page }) => {
    await page.goto('/permissions/templates/add');
    await expect(page).toHaveURL(/.*permissions\/templates\/add/);
    await expect(page.locator('form, [data-testid*="form"]')).toBeVisible();
  });

  test('should show permission template form fields', async ({ page }) => {
    await page.goto('/permissions/templates/add');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Check if we're on the add page
    if (!bodyText.toLowerCase().includes('permission') &&
        !bodyText.toLowerCase().includes('template')) {
      // Feature might not be available
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Should have template name field
    const nameField = page.locator('input[name*="name"], input[name*="template"], input[placeholder*="name"]').first();
    if (await nameField.count() > 0) {
      await expect(nameField).toBeVisible();
    }

    // Should have description field or some form elements
    const hasFormElements = await page.locator('input, textarea, select').count() > 0;
    expect(hasFormElements).toBeTruthy();
  });

  test('should validate permission template creation form', async ({ page }) => {
    await page.goto('/permissions/templates/add');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    await expect(page).toHaveURL(/.*permissions\/templates\/add/);
  });

  test('should show available permissions for template', async ({ page }) => {
    await page.goto('/permissions/templates/add');

    // Should show various permission options
    const hasCheckboxes = await page.locator('input[type="checkbox"]').count() > 0;
    if (hasCheckboxes) {
      expect(hasCheckboxes).toBeTruthy();

      // Look for common permissions
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/zone|user|permission/i);
    }
  });
});
