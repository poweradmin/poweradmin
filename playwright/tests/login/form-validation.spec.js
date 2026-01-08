import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Login Form Validation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/index.php?page=login');
  });

  test('should show error for empty fields', async ({ page }) => {
    await page.click('[data-testid="login-button"]');

    await expect(page.locator('[data-testid="username-error"]')).toBeVisible();
    await expect(page.locator('[data-testid="password-error"]')).toBeVisible();
  });

  test('should show error for empty username only', async ({ page }) => {
    await page.fill('[data-testid="password-input"]', 'somepassword');
    await page.click('[data-testid="login-button"]');

    await expect(page.locator('[data-testid="username-error"]')).toBeVisible();
  });

  test('should show error for empty password only', async ({ page }) => {
    await page.fill('[data-testid="username-input"]', 'someuser');
    await page.click('[data-testid="login-button"]');

    await expect(page.locator('[data-testid="password-error"]')).toBeVisible();
  });
});
