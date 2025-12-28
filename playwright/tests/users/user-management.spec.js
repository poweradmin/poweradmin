import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('User Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access users list page', async ({ page }) => {
    await page.goto('/index.php?page=users');
    await expect(page).toHaveURL(/page=users/);
    await expect(page.locator('h1, h2, h3, .page-title').first()).toBeVisible();
  });

  test('should display users list or empty state', async ({ page }) => {
    await page.goto('/index.php?page=users');

    // Should show either users table or empty state
    const hasTable = await page.locator('table, .table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table, .table').first()).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No users|users|empty/i);
    }
  });

  test('should access add user page', async ({ page }) => {
    await page.goto('/index.php?page=add_user');
    await expect(page).toHaveURL(/page=add_user/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should show user creation form fields', async ({ page }) => {
    await page.goto('/index.php?page=add_user');

    // Username field
    await expect(page.locator('input[name*="username"], input[name*="user"]').first()).toBeVisible();

    // Email field (if present)
    const hasEmail = await page.locator('input[name*="email"], input[type="email"]').count() > 0;
    if (hasEmail) {
      await expect(page.locator('input[name*="email"], input[type="email"]').first()).toBeVisible();
    }

    // Password field
    await expect(page.locator('input[type="password"]').first()).toBeVisible();
  });

  test('should validate user creation form', async ({ page }) => {
    await page.goto('/index.php?page=add_user');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    const currentUrl = page.url();
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.toLowerCase().includes('error') ||
                     bodyText.toLowerCase().includes('required') ||
                     currentUrl.includes('add_user');
    expect(hasError).toBeTruthy();
  });

  test('should require username for new user', async ({ page }) => {
    await page.goto('/index.php?page=add_user');

    // Fill other fields but leave username empty
    const emailField = page.locator('input[name*="email"], input[type="email"]').first();
    if (await emailField.count() > 0) {
      await emailField.fill('test@example.com');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    await expect(page).toHaveURL(/page=add_user/);
  });

  test('should have change password functionality', async ({ page }) => {
    await page.goto('/index.php?page=change_password');
    await expect(page).toHaveURL(/page=change_password/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should show password change form fields', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    // Password fields should be visible
    const passwordFields = await page.locator('input[type="password"]').count();
    expect(passwordFields).toBeGreaterThan(0);
  });
});
