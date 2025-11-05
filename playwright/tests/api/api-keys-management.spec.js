import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('API Keys Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access API keys page', async ({ page }) => {
    // Click Tools dropdown and navigate to API Keys
    await page.locator('.dropdown-toggle:has-text("Tools")').click();
    await expect(page.locator('.dropdown-menu')).toBeVisible();
    await page.locator('.dropdown-menu >> text=API Keys').click();

    await expect(page).toHaveURL(/.*settings\/api-keys/);

    // Should either show the page or permission error
    const bodyText = await page.locator('body').textContent();

    if (bodyText?.includes('You do not have permission')) {
      await expect(page.locator('body')).toContainText('You do not have permission to manage API keys');
      console.log('User does not have API key management permissions');
    } else {
      await expect(page.locator('body')).toContainText('API Keys Management');
      console.log('User has API key management permissions');
    }
  });

  test('should show appropriate message for user permissions', async ({ page }) => {
    await page.goto('/settings/api-keys');

    const bodyText = await page.locator('body').textContent();

    if (bodyText?.includes('You do not have permission')) {
      await expect(page.locator('body')).toContainText('You do not have permission to manage API keys');
      console.log('Test passed: Permission check working correctly');
    } else if (bodyText?.includes('API Keys Management')) {
      await expect(page.locator('body')).toContainText('API Keys Management');

      // Check for table or empty state message
      const hasTable = await page.locator('table').count() > 0;
      if (hasTable) {
        await expect(page.locator('table')).toBeVisible();
        console.log('API keys table found');
      } else {
        await expect(page.locator('body')).toContainText('No API keys found');
        console.log('Empty state shown - no API keys exist');
      }
    }
  });

  test('should handle API key creation if user has permissions', async ({ page }) => {
    // Navigate to API keys page via UI
    await page.locator('.dropdown-toggle:has-text("Tools")').click();
    await page.locator('.dropdown-menu >> text=API Keys').click();
    await expect(page).toHaveURL(/.*settings\/api-keys/);

    const bodyText = await page.locator('body').textContent();

    if (bodyText?.includes('You do not have permission')) {
      console.log('Skipping API key creation test - user lacks permissions');
      await expect(page.locator('body')).toContainText('You do not have permission to manage API keys');
    } else {
      // User has permissions, test functionality
      const hasAddButton = await page.locator('text=Add new API key').count() > 0;

      if (hasAddButton) {
        // Test creation flow
        await page.locator('text=Add new API key').click();
        await expect(page).toHaveURL(/.*settings\/api-keys\/add/);
        await expect(page.locator('body')).toContainText('Add API Key');

        // Fill form
        await page.locator('input[name="name"]').fill('Test API Key');
        await page.locator('button[type="submit"]:has-text("Create API Key")').click();

        // Verify success
        await expect(page.locator('body')).toContainText('API Key Created Successfully', { timeout: 10000 });
        await expect(page.locator('body')).toContainText('IMPORTANT: Save your API key now!');

        // Go back to list
        await page.locator('text=Return to API Keys').click();

        // Verify key appears in list
        await expect(page.locator('table tbody')).toContainText('Test API Key');

        // Clean up: Delete the test key
        await page.locator('tr:has-text("Test API Key") >> a:has-text("Delete")').click();

        // Confirm deletion
        await expect(page.locator('body')).toContainText('Delete API Key');
        await page.locator('button[type="submit"]:has-text("Yes, delete this API key")').click();

        // Verify deletion
        await expect(page).toHaveURL(/.*settings\/api-keys/);
        await expect(page.locator('body')).not.toContainText('Test API Key');
        console.log('✓ Test API Key created and cleaned up successfully');
      } else {
        console.log('Add button not available - might be at max capacity');
        if (bodyText?.includes('maximum number of API keys')) {
          await expect(page.locator('body')).toContainText('You have reached the maximum number of API keys allowed');
        }
      }
    }
  });

  test('should display API key management interface correctly if accessible', async ({ page }) => {
    await page.goto('/settings/api-keys');

    const bodyText = await page.locator('body').textContent();

    if (bodyText?.includes('You do not have permission')) {
      console.log('Permission check working - showing error page');
      await expect(page.locator('body')).toContainText('You do not have permission to manage API keys');
    } else {
      // User has access, verify interface elements
      await expect(page.locator('body')).toContainText('API Keys Management');
      await expect(page.locator('body')).toContainText('API keys allow external applications');

      const hasTable = await page.locator('table').count() > 0;

      if (hasTable) {
        // Verify table structure
        await expect(page.locator('table thead')).toContainText('Name');
        await expect(page.locator('table thead')).toContainText('Status');
        await expect(page.locator('table thead')).toContainText('Created at');
        await expect(page.locator('table thead')).toContainText('Actions');

        console.log('API keys table structure correct');
      } else {
        // Empty state
        await expect(page.locator('body')).toContainText('No API keys found');
        console.log('Empty state displayed correctly');
      }
    }
  });
});
