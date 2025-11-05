import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Slave Zones Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access add slave zone page', async ({ page }) => {
    await page.goto('/zones/add/slave');
    await expect(page).toHaveURL(/.*zones\/add\/slave/);
    await expect(page.locator('form, [data-testid*="form"]')).toBeVisible();
  });

  test('should show slave zone form fields', async ({ page }) => {
    await page.goto('/zones/add/slave');

    // Should have zone name field
    await expect(
      page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]')
    ).toBeVisible();

    // Should have master server field
    await expect(
      page.locator('input[name*="master"], input[name*="server"], input[placeholder*="master"], textarea[name*="master"]')
    ).toBeVisible();
  });

  test('should validate slave zone creation form', async ({ page }) => {
    await page.goto('/zones/add/slave');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    await expect(page).toHaveURL(/.*zones\/add\/slave/);
  });

  test('should require master server for slave zone', async ({ page }) => {
    await page.goto('/zones/add/slave');

    // Fill zone name but leave master empty
    await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]')
      .first()
      .fill('test-slave.example.com');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    await expect(page).toHaveURL(/.*zones\/add\/slave/);
  });
});
