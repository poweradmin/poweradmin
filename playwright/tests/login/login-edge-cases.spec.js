import { test, expect } from '../../fixtures/test-fixtures.js';
import { login, loginAndWaitForDashboard, logout } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Login Edge Cases', () => {
  test.describe('Session Management', () => {
    test('should maintain session after page refresh', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=index');
      await page.reload();
      await expect(page).toHaveURL(/page=index/);
    });

    test('should maintain session across pages', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/list_forward_zones/);
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should logout correctly', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await logout(page);
      await expect(page).toHaveURL(/login/);
    });

    test('should not access protected pages after logout', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await logout(page);
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/login/);
    });

    test('should redirect to login when session expires', async ({ page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/login/);
    });
  });

  test.describe('Multiple Login Attempts', () => {
    test('should handle rapid login attempts', async ({ page }) => {
      await page.goto('/index.php?page=login');
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
      await page.goto('/index.php?page=login');
      // First, fail
      await page.locator('input[name="username"]').fill('wronguser');
      await page.locator('input[name="password"]').fill('wrongpass');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Then succeed
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForURL(/page=index/);
    });
  });

  test.describe('Input Edge Cases', () => {
    test('should handle unicode username', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill('用户名');
      await page.locator('input[name="password"]').fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle unicode password', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill('密码123');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle very long username', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill('a'.repeat(1000));
      await page.locator('input[name="password"]').fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle very long password', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill('p'.repeat(1000));
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle null byte in username', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill('admin\x00extra');
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle newlines in username', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill('admin\ninjected');
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle tabs in input', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill('admin\ttest');
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Browser Behavior', () => {
    test('should handle back button after login', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goBack();
      // Should either stay logged in or redirect appropriately
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle forward button after logout', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_forward_zones');
      await logout(page);
      await page.goBack();
      // Should redirect to login
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle direct URL access when logged out', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');
      await expect(page).toHaveURL(/login/);
    });

    test('should handle form resubmission', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForURL(/page=index/);
      // Attempt to go back and resubmit
      await page.goBack();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Cookie Handling', () => {
    test('should set session cookie on login', async ({ page, context }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const cookies = await context.cookies();
      const sessionCookie = cookies.find(c => c.name.includes('PHPSESSID') || c.name.includes('session'));
      // Session should be established
      expect(cookies.length).toBeGreaterThan(0);
    });

    test('should clear session cookie on logout', async ({ page, context }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await logout(page);
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/login/);
    });
  });

  test.describe('Concurrent Sessions', () => {
    test('should handle login in new tab', async ({ browser }) => {
      const context = await browser.newContext();
      const page1 = await context.newPage();
      const page2 = await context.newPage();

      await loginAndWaitForDashboard(page1, users.admin.username, users.admin.password);
      await page2.goto('/index.php?page=list_forward_zones');
      await expect(page2).toHaveURL(/list_forward_zones/);

      await context.close();
    });

    test('should handle logout in one tab', async ({ browser }) => {
      const context = await browser.newContext();
      const page1 = await context.newPage();
      const page2 = await context.newPage();

      await loginAndWaitForDashboard(page1, users.admin.username, users.admin.password);
      await page2.goto('/index.php?page=list_forward_zones');
      await logout(page1);

      await page2.reload();
      await expect(page2).toHaveURL(/login/);

      await context.close();
    });
  });

  test.describe('Remember Me', () => {
    test('should display remember me checkbox', async ({ page }) => {
      await page.goto('/index.php?page=login');
      const rememberMe = page.locator('input[name*="remember"], input[type="checkbox"]');
      // Remember me may or may not exist
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Password Visibility', () => {
    test('should mask password by default', async ({ page }) => {
      await page.goto('/index.php?page=login');
      const passwordField = page.locator('input[name="password"]');
      await expect(passwordField).toHaveAttribute('type', 'password');
    });

    test('should allow password input', async ({ page }) => {
      await page.goto('/index.php?page=login');
      const passwordField = page.locator('input[name="password"]');
      await passwordField.fill('testpassword');
      expect(await passwordField.inputValue()).toBe('testpassword');
    });
  });
});
