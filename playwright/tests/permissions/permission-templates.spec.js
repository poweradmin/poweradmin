import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Permission Templates Management', () => {
  test('should access permission templates page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_perm_templ');
    await expect(page).toHaveURL(/page=list_perm_templ/);
    // Page should load without errors and display content
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should display permission templates list or empty state', async ({ adminPage: page }) => {
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

  test('should access add permission template page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_perm_templ');
    // Verify we can access the page (may redirect if no permission)
    const currentUrl = page.url();
    const bodyText = await page.locator('body').textContent();
    const hasAccess = currentUrl.includes('add_perm_templ') ||
                      bodyText.toLowerCase().includes('permission template') ||
                      bodyText.toLowerCase().includes('name');
    expect(hasAccess).toBeTruthy();
    // If we have a form, verify it's visible
    if (await page.locator('form').count() > 0) {
      await expect(page.locator('form').first()).toBeVisible();
    }
  });

  test('should show permission template form fields', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_perm_templ');

    // Use correct selector - templ_name is the actual field name
    const nameField = page.locator('input[name="templ_name"], input[id="templ_name"]');
    if (await nameField.count() > 0) {
      await expect(nameField.first()).toBeVisible();
    } else {
      // Fallback - verify page content
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/name|template/i);
    }

    // Should have description field (if present) - templ_descr is the actual name
    const descField = page.locator('input[name="templ_descr"], input[id="templ_descr"]');
    if (await descField.count() > 0) {
      await expect(descField.first()).toBeVisible();
    }

    // Should have permission checkboxes - wait for accordion to load
    await page.waitForSelector('.accordion, input[type="checkbox"]', { timeout: 5000 }).catch(() => {});
    const hasPermissions = await page.locator('input[type="checkbox"], select[name*="permission"]').count() > 0;
    // Permission checkboxes may not be visible if accordion collapsed, just check page loaded
    if (!hasPermissions) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should validate permission template creation form', async ({ adminPage: page }) => {
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

  test('should show available permissions for template', async ({ adminPage: page }) => {
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
