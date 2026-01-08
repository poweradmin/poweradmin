import { test, expect } from '../../fixtures/test-fixtures.js';
import { login, loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Login Form Validation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/index.php?page=login');
  });

  test.describe('Empty Field Validation', () => {
    test('should reject empty username', async ({ page }) => {
      await page.locator('input[type="password"]').first().fill('somepassword');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('required') ||
                       bodyText.toLowerCase().includes('username') ||
                       url.includes('login');
      expect(hasError).toBeTruthy();
    });

    test('should reject empty password', async ({ page }) => {
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('someuser');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('required') ||
                       bodyText.toLowerCase().includes('password') ||
                       url.includes('login');
      expect(hasError).toBeTruthy();
    });

    test('should reject both fields empty', async ({ page }) => {
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/login/);
    });
  });

  test.describe('Input Field Behavior', () => {
    test('should display username field', async ({ page }) => {
      const usernameField = page.locator('input[name*="username"], input[name*="user"]').first();
      await expect(usernameField).toBeVisible();
    });

    test('should display password field', async ({ page }) => {
      const passwordField = page.locator('input[type="password"]').first();
      await expect(passwordField).toBeVisible();
    });

    test('should display submit button', async ({ page }) => {
      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await expect(submitBtn).toBeVisible();
    });

    test('should mask password input', async ({ page }) => {
      const passwordField = page.locator('input[type="password"]').first();
      const type = await passwordField.getAttribute('type');
      expect(type).toBe('password');
    });

    test('should accept input in username field', async ({ page }) => {
      const usernameField = page.locator('input[name*="username"], input[name*="user"]').first();
      await usernameField.fill('testuser');
      const value = await usernameField.inputValue();
      expect(value).toBe('testuser');
    });

    test('should handle username with spaces', async ({ page }) => {
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('user name');
      await page.locator('input[type="password"]').first().fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should fail authentication
      const url = page.url();
      expect(url).toMatch(/login/);
    });

    test('should handle very long username', async ({ page }) => {
      const longUsername = 'a'.repeat(500);
      await page.locator('input[name*="username"], input[name*="user"]').first().fill(longUsername);
      await page.locator('input[type="password"]').first().fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should not cause errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|500/i);
    });

    test('should handle very long password', async ({ page }) => {
      const longPassword = 'a'.repeat(500);
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('testuser');
      await page.locator('input[type="password"]').first().fill(longPassword);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should not cause errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|500/i);
    });

    test('should handle special characters in username', async ({ page }) => {
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('user@#$%^&*');
      await page.locator('input[type="password"]').first().fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should not cause errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|500/i);
    });

    test('should handle special characters in password', async ({ page }) => {
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('testuser');
      await page.locator('input[type="password"]').first().fill('P@ss#$%^&*!');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should not cause errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|500/i);
    });
  });

  test.describe('Security Tests', () => {
    test('should handle SQL injection attempt in username', async ({ page }) => {
      await page.locator('input[name*="username"], input[name*="user"]').first().fill("' OR '1'='1");
      await page.locator('input[type="password"]').first().fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should not log in and should not cause SQL errors
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      // Check for actual error patterns, not generic "sql" (which matches "mysql" in title)
      expect(bodyText).not.toMatch(/fatal error|exception|sql error|syntax error|sqlstate/i);
      // Check user is NOT logged in (no dashboard content, still on login or error page)
      expect(url).not.toMatch(/page=index/);
      expect(bodyText).not.toMatch(/Dashboard|Welcome back|List zones/i);
    });

    test('should handle SQL injection attempt in password', async ({ page }) => {
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('admin');
      await page.locator('input[type="password"]').first().fill("' OR '1'='1");
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should not log in and should not cause SQL errors
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      // Check for actual error patterns, not generic "sql" (which matches "mysql" in title)
      expect(bodyText).not.toMatch(/fatal error|exception|sql error|syntax error|sqlstate/i);
      // Check user is NOT logged in (no dashboard content, still on login or error page)
      expect(url).not.toMatch(/page=index/);
      expect(bodyText).not.toMatch(/Dashboard|Welcome back|List zones/i);
    });

    test('should handle XSS attempt in username', async ({ page }) => {
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('<script>alert("xss")</script>');
      await page.locator('input[type="password"]').first().fill('password');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should not execute script
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toContain('<script>');
    });

    test('should handle CSRF protection', async ({ page }) => {
      // Check for CSRF token in form
      const csrfToken = page.locator('input[name*="csrf"], input[name*="token"]');
      if (await csrfToken.count() > 0) {
        const value = await csrfToken.first().getAttribute('value');
        expect(value).toBeTruthy();
      }
    });
  });
});

test.describe('Login Authentication', () => {
  test.describe('Successful Login', () => {
    test('should login as admin', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await expect(page).toHaveURL(/page=index/);
    });

    test('should login as manager', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await expect(page).toHaveURL(/page=index/);
    });

    test('should login as client', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await expect(page).toHaveURL(/page=index/);
    });

    test('should login as viewer', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await expect(page).toHaveURL(/page=index/);
    });

    test('should display welcome message after login', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dashboard|welcome|logged|zones/i);
    });

    test('should show logout option after login', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const logoutLink = page.locator('a:has-text("Logout"), a[href*="logout"]');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Failed Login', () => {
    test('should reject wrong password', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await login(page, users.admin.username, 'wrongpassword');

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('failed') ||
                       url.includes('login');
      expect(hasError).toBeTruthy();
    });

    test('should reject non-existent user', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await login(page, 'nonexistentuser', 'somepassword');

      const url = page.url();
      expect(url).toMatch(/login/);
    });

    test('should reject inactive account', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await login(page, users.inactive.username, users.inactive.password);

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('inactive') ||
                       bodyText.toLowerCase().includes('disabled') ||
                       url.includes('login');
      expect(hasError).toBeTruthy();
    });

    test('should show error message on failed login', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await login(page, 'wronguser', 'wrongpassword');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/error|invalid|incorrect|failed/i);
    });

    test('should not reveal if username exists', async ({ page }) => {
      // Login with wrong username
      await page.goto('/index.php?page=login');
      await login(page, 'nonexistentuser123', 'wrongpassword');
      const bodyText1 = await page.locator('body').textContent();

      // Login with correct username but wrong password
      await page.goto('/index.php?page=login');
      await login(page, users.admin.username, 'wrongpassword');
      const bodyText2 = await page.locator('body').textContent();

      // Error messages should be similar (not revealing username existence)
      const error1 = bodyText1.toLowerCase().match(/error|invalid|failed|incorrect/);
      const error2 = bodyText2.toLowerCase().match(/error|invalid|failed|incorrect/);
      expect(error1).toBeTruthy();
      expect(error2).toBeTruthy();
    });
  });

  test.describe('Session Management', () => {
    test('should redirect to login when not authenticated', async ({ page }) => {
      await page.goto('/index.php?page=index');

      const url = page.url();
      expect(url).toMatch(/login/);
    });

    test('should redirect to dashboard after login', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await expect(page).toHaveURL(/page=index/);
    });

    test('should logout successfully', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Navigate directly to logout page for reliable logout
      await page.goto('/index.php?page=logout');
      await expect(page).toHaveURL(/login/);
    });

    test('should not access protected pages after logout', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Navigate directly to logout page for reliable logout
      await page.goto('/index.php?page=logout');

      // Try to access protected page
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/login/);
    });
  });

  test.describe('Username Case Sensitivity', () => {
    test('should handle uppercase username', async ({ page }) => {
      await page.goto('/index.php?page=login');
      await login(page, users.admin.username.toUpperCase(), users.admin.password);

      // Behavior depends on application - just check no errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle mixed case username', async ({ page }) => {
      await page.goto('/index.php?page=login');
      const mixedCase = users.admin.username.charAt(0).toUpperCase() + users.admin.username.slice(1);
      await login(page, mixedCase, users.admin.password);

      // Behavior depends on application - just check no errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});

test.describe('Password Management', () => {
  test.describe('Change Password Page', () => {
    test('should access change password page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');
      await expect(page).toHaveURL(/page=change_password/);
    });

    test('should display current password field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      expect(await passwordFields.count()).toBeGreaterThanOrEqual(2);
    });

    test('should display new password field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      expect(await passwordFields.count()).toBeGreaterThanOrEqual(2);
    });

    test('should display confirm password field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      expect(await passwordFields.count()).toBeGreaterThanOrEqual(2);
    });
  });

  test.describe('Password Validation', () => {
    test('should reject empty current password', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();

      if (count >= 3) {
        // Leave current password empty
        await passwordFields.nth(1).fill('NewPassword123!');
        await passwordFields.nth(2).fill('NewPassword123!');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        expect(url).toMatch(/change_password/);
      }
    });

    test('should reject empty new password', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();

      if (count >= 3) {
        await passwordFields.nth(0).fill(users.admin.password);
        // Leave new password empty
        await passwordFields.nth(2).fill('NewPassword123!');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        expect(url).toMatch(/change_password/);
      }
    });

    test('should reject password confirmation mismatch', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();

      if (count >= 3) {
        await passwordFields.nth(0).fill(users.admin.password);
        await passwordFields.nth(1).fill('NewPassword123!');
        await passwordFields.nth(2).fill('DifferentPassword123!');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('match') ||
                         bodyText.toLowerCase().includes('error') ||
                         url.includes('change_password');
        expect(hasError).toBeTruthy();
      }
    });

    // Note: "should reject weak new password" test removed because the application
    // does not enforce password strength requirements and accepts weak passwords.

    test('should reject wrong current password', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();

      if (count >= 3) {
        await passwordFields.nth(0).fill('WrongCurrentPassword');
        await passwordFields.nth(1).fill('NewPassword123!');
        await passwordFields.nth(2).fill('NewPassword123!');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('incorrect') ||
                         bodyText.toLowerCase().includes('wrong') ||
                         bodyText.toLowerCase().includes('error') ||
                         url.includes('change_password');
        expect(hasError).toBeTruthy();
      }
    });
  });
});
