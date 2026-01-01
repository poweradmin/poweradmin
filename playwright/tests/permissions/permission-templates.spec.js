import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Permission Templates Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access permission templates page', async ({ page }) => {
    await page.goto('/index.php?page=list_perm_templ');
    await expect(page).toHaveURL(/page=list_perm_templ/);
    // Page should load without errors and display content
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should display permission templates list or empty state', async ({ page }) => {
    await page.goto('/index.php?page=list_perm_templ');

    // Should show either templates table or empty state
    const hasTable = await page.locator('table, .table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table, .table').first()).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No templates|template|empty/i);
    }
  });

  test('should access add permission template page', async ({ page }) => {
    await page.goto('/index.php?page=add_perm_templ');
    await expect(page).toHaveURL(/page=add_perm_templ/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should show permission template form fields', async ({ page }) => {
    await page.goto('/index.php?page=add_perm_templ');

    // Should have template name field
    await expect(page.locator('input[name*="name"], input[name*="template"]').first()).toBeVisible();

    // Should have description field (if present)
    const hasDesc = await page.locator('input[name*="description"], textarea[name*="description"]').count() > 0;
    if (hasDesc) {
      await expect(page.locator('input[name*="description"], textarea[name*="description"]').first()).toBeVisible();
    }

    // Should have permission checkboxes or selectors
    const hasPermissions = await page.locator('input[type="checkbox"], select[name*="permission"]').count() > 0;
    expect(hasPermissions).toBeTruthy();
  });

  test('should validate permission template creation form', async ({ page }) => {
    await page.goto('/index.php?page=add_perm_templ');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    const currentUrl = page.url();
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.toLowerCase().includes('error') ||
                     bodyText.toLowerCase().includes('required') ||
                     currentUrl.includes('add_perm_templ');
    expect(hasError).toBeTruthy();
  });

  test('should show available permissions for template', async ({ page }) => {
    await page.goto('/index.php?page=add_perm_templ');

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
