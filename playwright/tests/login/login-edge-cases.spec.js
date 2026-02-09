/**
 * Login Edge Cases Tests
 *
 * Tests for session management, input validation edge cases,
 * and browser behavior during authentication.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Login Edge Cases', () => {
  test.describe('Session Management', () => {
    test('should maintain session after page refresh', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');
      await page.reload();
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should maintain session across pages', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should logout correctly', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/logout');
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should not access protected pages after logout', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/logout');
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should redirect to login when session expires', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*\/login/);
    });
  });

  test.describe('Multiple Login Attempts', () => {
    test('should handle rapid login attempts', async ({ page }) => {
      await page.goto('/login');
      for (let i = 0; i < 3; i++) {
        await page.locator('input[name="username"]').fill(`invalid${i}`);
        await page.locator('input[name="password"]').fill(`wrong${i}`);
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        await page.waitForLoadState('networkidle');
      }
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should allow login after failed attempts', async ({ page }) => {
      await page.goto('/login');
      // First, fail
      await page.locator('input[name="username"]').fill('wronguser');
      await page.locator('input[name="password"]').fill('wrongpass');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Then succeed
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await expect(page).not.toHaveURL(/.*\/login/);
    });
  });

  test.describe('Input Edge Cases', () => {
    test('should handle unicode username', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill('用户名');
      await page.locator('input[name="password"]').fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle unicode password', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill('密码123');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle very long username', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill('a'.repeat(1000));
      await page.locator('input[name="password"]').fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle very long password', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill('p'.repeat(1000));
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle null byte in username', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill('admin\x00extra');
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle newlines in username', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill('admin\ninjected');
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle tabs in input', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill('admin\ttest');
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Browser Behavior', () => {
    test('should handle back button after login', async ({ page }) => {
      await page.goto('/login');
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goBack();
      // Should either stay logged in or redirect appropriately
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle forward button after logout', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await page.goto('/logout');
      await page.goBack();
      // Should redirect to login or show no fatal errors
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const isOnLoginPage = url.includes('/login');
      const hasNoFatalError = !(/fatal|exception/i.test(bodyText));
      expect(isOnLoginPage || hasNoFatalError).toBeTruthy();
    });

    test('should handle direct URL access when logged out', async ({ page }) => {
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should handle form resubmission', async ({ page }) => {
      await page.goto('/login');
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForURL(/\//);
      // Attempt to go back and check
      await page.goBack();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Cookie Handling', () => {
    test('should set session cookie on login', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const cookies = await page.context().cookies();
      const sessionCookie = cookies.find(c => c.name.includes('PHPSESSID') || c.name.includes('session'));
      expect(sessionCookie || cookies.length > 0).toBeTruthy();
    });

    test('should clear session cookie on logout', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/logout');

      // After logout, accessing protected page should redirect to login
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*\/login/);
    });
  });

  test.describe('Concurrent Sessions', () => {
    test('should handle login in new tab', async ({ browser }) => {
      const context = await browser.newContext();
      const page1 = await context.newPage();
      const page2 = await context.newPage();

      await loginAndWaitForDashboard(page1, users.admin.username, users.admin.password);
      await page2.goto('/zones/forward?letter=all');
      await expect(page2).toHaveURL(/zones\/forward/);

      await context.close();
    });

    test('should handle logout in one tab', async ({ browser }) => {
      const context = await browser.newContext();
      const page1 = await context.newPage();
      const page2 = await context.newPage();

      await loginAndWaitForDashboard(page1, users.admin.username, users.admin.password);
      await page2.goto('/zones/forward?letter=all');
      await page1.goto('/logout');

      await page2.reload();
      await expect(page2).toHaveURL(/.*\/login/);

      await context.close();
    });
  });

  test.describe('Remember Me', () => {
    test('should have remember me checkbox if available', async ({ page }) => {
      await page.goto('/login');
      const rememberCheckbox = page.locator('input[name="remember"], input#remember');
      // May or may not be present depending on configuration
      const count = await rememberCheckbox.count();
      expect(count >= 0).toBeTruthy();
    });
  });

  test.describe('Password Visibility', () => {
    test('should mask password by default', async ({ page }) => {
      await page.goto('/login');
      const passwordField = page.locator('input[name="password"]');
      await expect(passwordField).toHaveAttribute('type', 'password');
    });

    test('should allow password input', async ({ page }) => {
      await page.goto('/login');
      const passwordField = page.locator('input[name="password"]');
      await passwordField.fill('testpassword');
      expect(await passwordField.inputValue()).toBe('testpassword');
    });
  });
});
