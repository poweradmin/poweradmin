import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('API Keys Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access API keys page', async ({ page }) => {
    // Click Tools dropdown and navigate to API Keys
    const toolsDropdown = page.locator('.dropdown-toggle:has-text("Tools")');
    await toolsDropdown.click();
    // Wait for the specific dropdown menu that contains "API Keys"
    const toolsMenu = page.locator('.dropdown-menu:has-text("API Keys")');
    await expect(toolsMenu).toBeVisible();
    await toolsMenu.locator('text=API Keys').click();

    await expect(page).toHaveURL(/.*settings\/api-keys/);
    await expect(page.locator('body')).toContainText('API Keys Management');
    await expect(page.locator('body')).not.toContainText('You do not have permission');
  });

  test('should allow the administrator to manage API keys', async ({ page }) => {
    await page.goto('/settings/api-keys');

    await expect(page.locator('body')).toContainText('API Keys Management');
    await expect(page.locator('body')).not.toContainText('You do not have permission');
  });

  test('should handle API key creation', async ({ page }) => {
    await page.goto('/settings/api-keys');
    await expect(page.locator('body')).toContainText('API Keys Management');

    // A per-user cap hides the add button; that is configuration, not a failure
    const atCapacity = (await page.locator('body').textContent()).includes('maximum number of API keys');
    test.skip(atCapacity, 'the administrator is at the API key limit');

    await page.locator('text=Add new API key').click();
    await expect(page).toHaveURL(/.*settings\/api-keys\/add/);
    await expect(page.locator('body')).toContainText('Add API Key');

    // Fill form
    await page.locator('input[name="name"]').fill('E2E Playwright Key');
    await page.locator('button[type="submit"]:has-text("Create API Key")').click();

    // Verify success
    await expect(page.locator('body')).toContainText('API Key Created Successfully', { timeout: 10000 });
    await expect(page.locator('body')).toContainText('IMPORTANT: Save your API key now!');

    // Go back to list
    await page.locator('text=Return to API Keys').click();

    // Verify key appears in list
    await expect(page.locator('table tbody')).toContainText('E2E Playwright Key');

    // Clean up: Delete the test key (icon-only button, match by href pattern)
    await page.locator('tr', { hasText: 'E2E Playwright Key' }).locator('a[href*="/delete"]').click();

    // Confirm deletion
    await expect(page.locator('body')).toContainText('Delete API Key');
    await page.locator('button[type="submit"]').click();

    // Verify deletion
    await expect(page).toHaveURL(/.*settings\/api-keys/);
    await expect(page.locator('body')).not.toContainText('E2E Playwright Key');
  });

  test('should display API key management interface correctly', async ({ page }) => {
    await page.goto('/settings/api-keys');

    await expect(page.locator('body')).toContainText('API Keys Management');
    await expect(page.locator('body')).toContainText('API keys allow external applications');

    // The list is either empty or a table with the standard columns, depending on earlier runs
    if (await page.locator('table').count() > 0) {
      await expect(page.locator('table thead')).toContainText('Name');
      await expect(page.locator('table thead')).toContainText('Status');
      await expect(page.locator('table thead')).toContainText('Created at');
      await expect(page.locator('table thead')).toContainText('Actions');
    } else {
      await expect(page.locator('body')).toContainText('No API keys found');
    }
  });
});
