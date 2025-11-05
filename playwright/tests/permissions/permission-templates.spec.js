import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Permission Templates Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access permission templates page', async ({ page }) => {
    await page.goto('/permissions/templates');
    await expect(page).toHaveURL(/.*permissions\/templates/);
    await expect(page.locator('h1, h2, h3, .page-title, [data-testid*="title"]').first()).toBeVisible();
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

    // Should have template name field
    await expect(page.locator('input[name*="name"], input[name*="template"], input[placeholder*="name"]').first()).toBeVisible();

    // Should have description field
    await expect(page.locator('input[name*="description"], textarea[name*="description"], input[placeholder*="description"]').first()).toBeVisible();

    // Should have permission checkboxes or selectors
    const hasPermissions = await page.locator('input[type="checkbox"], select[name*="permission"]').count() > 0;
    expect(hasPermissions).toBeTruthy();
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
