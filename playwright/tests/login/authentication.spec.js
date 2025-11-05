import { test, expect } from '@playwright/test';
import { login } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Login Authentication', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should redirect to dashboard on successful login', async ({ page }) => {
    await login(page, users.admin.username, users.admin.password);
    await expect(page).toHaveURL('/');
  });

  test('should remain on login page for invalid credentials', async ({ page }) => {
    await login(page, users.invalidUser.username, users.invalidUser.password);
    await expect(page).toHaveURL(/.*login/);
  });

  test('should display error message for invalid login', async ({ page }) => {
    await page.fill('[data-testid="username-input"]', users.invalidUser.username);
    await page.fill('[data-testid="password-input"]', users.invalidUser.password);
    await page.click('[data-testid="login-button"]');

    await expect(page.locator('[data-testid="session-error"]')).toBeVisible();
  });

  test('should login successfully with manager account', async ({ page }) => {
    await login(page, users.manager.username, users.manager.password);
    await expect(page).toHaveURL('/');
  });

  test('should login successfully with client account', async ({ page }) => {
    await login(page, users.client.username, users.client.password);
    await expect(page).toHaveURL('/');
  });

  test('should login successfully with viewer account', async ({ page }) => {
    await login(page, users.viewer.username, users.viewer.password);
    await expect(page).toHaveURL('/');
  });

  test('should not login with inactive account', async ({ page }) => {
    await login(page, users.inactive.username, users.inactive.password);
    // Inactive accounts should fail to login
    await expect(page).toHaveURL(/.*login/);
  });
});
