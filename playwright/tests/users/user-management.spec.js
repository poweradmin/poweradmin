import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('User Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access users list page', async ({ page }) => {
    await page.goto('/users');
    await expect(page).toHaveURL(/.*users/);
    await expect(page.locator('h1, h2, h3, .page-title, [data-testid*="title"]').first()).toBeVisible();
  });

  test('should display users list or empty state', async ({ page }) => {
    await page.goto('/users');

    // Should show either users table or empty state
    const hasTable = await page.locator('table, .table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table, .table')).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No users|users|empty/i);
    }
  });

  test('should access add user page', async ({ page }) => {
    await page.goto('/users/add');
    await expect(page).toHaveURL(/.*users\/add/);
    await expect(page.locator('form, [data-testid*="form"]')).toBeVisible();
  });

  test('should show user creation form fields', async ({ page }) => {
    await page.goto('/users/add');

    // Username field
    await expect(page.locator('input[name*="username"], input[name*="user"], input[placeholder*="username"]').first()).toBeVisible();

    // Email field
    await expect(page.locator('input[name*="email"], input[type="email"]').first()).toBeVisible();

    // Password field
    await expect(page.locator('input[name*="password"], input[type="password"]').first()).toBeVisible();
  });

  test('should validate user creation form', async ({ page }) => {
    await page.goto('/users/add');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    await expect(page).toHaveURL(/.*users\/add/);
  });

  test('should require username for new user', async ({ page }) => {
    await page.goto('/users/add');

    // Fill other fields but leave username empty
    await page.locator('input[name*="email"], input[type="email"]').first().fill('test@example.com');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    await expect(page).toHaveURL(/.*users\/add/);
  });

  test('should have change password functionality', async ({ page }) => {
    await page.goto('/password/change');
    await expect(page).toHaveURL(/.*password\/change/);
    await expect(page.locator('form, [data-testid*="form"]')).toBeVisible();
  });

  test('should show password change form fields', async ({ page }) => {
    await page.goto('/password/change');

    // Current password field
    await expect(page.locator('input[name*="current"], input[name*="old"]').first()).toBeVisible();

    // New password field
    await expect(page.locator('input[name*="new"], input[name*="password"]').first()).toBeVisible();
  });
});
