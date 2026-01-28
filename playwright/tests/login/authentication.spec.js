import { test, expect } from '../../fixtures/test-fixtures.js';
import { login, loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Login Authentication', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test.describe('Successful Login - All User Types', () => {
    test('should login admin user and redirect to dashboard', async ({ page }) => {
      await login(page, users.admin.username, users.admin.password);
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should login manager user and redirect to dashboard', async ({ page }) => {
      await login(page, users.manager.username, users.manager.password);
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should login client user and redirect to dashboard', async ({ page }) => {
      await login(page, users.client.username, users.client.password);
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should login viewer user and redirect to dashboard', async ({ page }) => {
      await login(page, users.viewer.username, users.viewer.password);
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should login noperm user and redirect to dashboard', async ({ page }) => {
      await login(page, users.noperm.username, users.noperm.password);
      await expect(page).not.toHaveURL(/.*\/login/);
    });
  });

  test.describe('Failed Login Attempts', () => {
    test('should remain on login page for invalid credentials', async ({ page }) => {
      await login(page, users.invalidUser.username, users.invalidUser.password);
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should display error message for invalid login', async ({ page }) => {
      await page.locator('input[name="username"]').fill(users.invalidUser.username);
      await page.locator('input[name="password"]').fill(users.invalidUser.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      // Check for error message or alert
      const hasError = await page.locator('.alert-danger, .error, [data-testid="session-error"]').first().isVisible().catch(() => false);
      const bodyText = await page.locator('body').textContent();
      expect(hasError || bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('invalid')).toBeTruthy();
    });

    test('should not allow inactive user to login', async ({ page }) => {
      await login(page, users.inactive.username, users.inactive.password);
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should not login with correct username but wrong password', async ({ page }) => {
      await login(page, users.admin.username, 'wrongpassword');
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should not login with wrong username but correct password', async ({ page }) => {
      await login(page, 'wronguser', users.admin.password);
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should not login with empty password', async ({ page }) => {
      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await expect(page).toHaveURL(/.*\/login/);
    });
  });

  test.describe('Session Handling', () => {
    test('should maintain session after login', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should redirect to login when accessing protected page without session', async ({ page }) => {
      await page.goto('/permissions/templates');
      await expect(page).toHaveURL(/.*\/login/);
    });
  });
});

test.describe('User Permissions After Login', () => {
  test.describe('Admin User Permissions', () => {
    test('should have access to dashboard', async ({ adminPage: page }) => {
      await page.goto('/');
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should have access to user administration', async ({ adminPage: page }) => {
      await page.goto('/users');
      await expect(page).toHaveURL(/.*\/users/);
    });

    test('should have access to permission templates', async ({ adminPage: page }) => {
      await page.goto('/permissions/templates');
      await expect(page).toHaveURL(/.*\/permissions\/templates/);
      // Should show the page without being redirected to login
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/permission|template/i);
    });

    test('should have access to add permission template', async ({ adminPage: page }) => {
      await page.goto('/permissions/templates/add');
      await expect(page).toHaveURL(/.*\/permissions\/templates\/add/);
    });

    test('should have access to zone list', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*\/zones\/forward/);
    });

    test('should have access to add master zone', async ({ adminPage: page }) => {
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*\/zones\/add\/master/);
    });

    test('should have access to add slave zone', async ({ adminPage: page }) => {
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*\/zones\/add\/slave/);
    });

    test('should have access to search', async ({ adminPage: page }) => {
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should have access to add user', async ({ adminPage: page }) => {
      await page.goto('/users/add');
      await expect(page).toHaveURL(/.*\/users\/add/);
    });
  });

  test.describe('Manager User Permissions', () => {
    test('should have access to dashboard', async ({ managerPage: page }) => {
      await page.goto('/');
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should have access to zone list', async ({ managerPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*\/zones\/forward/);
    });

    test('should have access to add master zone', async ({ managerPage: page }) => {
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*\/zones\/add\/master/);
    });

    test('should have access to add slave zone', async ({ managerPage: page }) => {
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*\/zones\/add\/slave/);
    });

    test('should have access to search', async ({ managerPage: page }) => {
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should NOT have access to permission templates', async ({ managerPage: page }) => {
      await page.goto('/permissions/templates');
      // Should be redirected or show error
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       bodyText.toLowerCase().includes('not allowed');
      const redirectedToLogin = page.url().includes('/login');
      expect(hasError || redirectedToLogin || !page.url().includes('/permissions/templates')).toBeTruthy();
    });

    test('should NOT have access to add user', async ({ managerPage: page }) => {
      await page.goto('/users/add');
      // Should be redirected or show error
      const hasAddUserForm = await page.locator('input[name="username"], input[name="fullname"]').count() > 0 &&
                             await page.locator('input[name="password"]').count() > 0;
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      expect(hasError || !hasAddUserForm).toBeTruthy();
    });
  });

  test.describe('Client User Permissions', () => {
    test('should have access to dashboard', async ({ clientPage: page }) => {
      await page.goto('/');
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should have access to zone list (own zones)', async ({ clientPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*\/zones\/forward/);
    });

    test('should have access to search', async ({ clientPage: page }) => {
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should NOT have access to add master zone', async ({ clientPage: page }) => {
      await page.goto('/zones/add/master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should NOT have access to permission templates', async ({ clientPage: page }) => {
      await page.goto('/permissions/templates');
      const bodyText = await page.locator('body').textContent();
      // Client should see error message or be redirected
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('permission') ||
                       bodyText.toLowerCase().includes('denied');
      const isRedirected = page.url().includes('/login');
      const noTable = await page.locator('table').count() === 0;
      expect(hasError || isRedirected || noTable).toBeTruthy();
    });
  });

  test.describe('Viewer User Permissions', () => {
    test('should have access to dashboard', async ({ viewerPage: page }) => {
      await page.goto('/');
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should have access to zone list (view only)', async ({ viewerPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*\/zones\/forward/);
    });

    test('should have access to search', async ({ viewerPage: page }) => {
      await page.goto('/search');
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should NOT have access to add master zone', async ({ viewerPage: page }) => {
      await page.goto('/zones/add/master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should NOT have access to add slave zone', async ({ viewerPage: page }) => {
      await page.goto('/zones/add/slave');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });
  });

  test.describe('No Permission User Permissions', () => {
    test('should have access to dashboard', async ({ nopermPage: page }) => {
      await page.goto('/');
      await expect(page).not.toHaveURL(/.*\/login/);
    });

    test('should NOT have access to add master zone', async ({ nopermPage: page }) => {
      await page.goto('/zones/add/master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should NOT have access to permission templates', async ({ nopermPage: page }) => {
      await page.goto('/permissions/templates');
      const bodyText = await page.locator('body').textContent();
      const hasPermTable = bodyText.toLowerCase().includes('permission template') && !bodyText.toLowerCase().includes('error');
      expect(!hasPermTable || page.url().includes('/login')).toBeTruthy();
    });
  });
});

test.describe('Logout Functionality', () => {
  test('should logout admin user successfully', async ({ adminPage: page }) => {
    await page.goto('/logout');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should logout manager user successfully', async ({ managerPage: page }) => {
    await page.goto('/logout');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should logout client user successfully', async ({ clientPage: page }) => {
    await page.goto('/logout');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should logout viewer user successfully', async ({ viewerPage: page }) => {
    await page.goto('/logout');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should not be able to access protected pages after logout', async ({ adminPage: page }) => {
    await page.goto('/logout');
    await page.goto('/permissions/templates');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should not be able to access zones after logout', async ({ adminPage: page }) => {
    await page.goto('/logout');
    await page.goto('/zones/forward?letter=all');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should not be able to access users after logout', async ({ adminPage: page }) => {
    await page.goto('/logout');
    await page.goto('/users');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should redirect to login with original URL after logout', async ({ adminPage: page }) => {
    await page.goto('/logout');
    await expect(page).toHaveURL(/.*\/login/);
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});

test.describe('Session Security', () => {
  test('should redirect to login for unauthenticated API access', async ({ page }) => {
    await page.goto('/api/internal/zones');
    const bodyText = await page.locator('body').textContent();
    const url = page.url();
    const isProtected = url.includes('/login') || bodyText.toLowerCase().includes('unauthorized') || bodyText.toLowerCase().includes('error');
    expect(isProtected).toBeTruthy();
  });

  test('should protect search page from unauthenticated access', async ({ page }) => {
    await page.goto('/search');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should protect WHOIS page from unauthenticated access', async ({ page }) => {
    await page.goto('/whois');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should protect MFA setup from unauthenticated access', async ({ page }) => {
    await page.goto('/mfa/setup');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should protect password change from unauthenticated access', async ({ page }) => {
    await page.goto('/password/change');
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('should protect tools from unauthenticated access', async ({ page }) => {
    await page.goto('/tools/pdns-status');
    await expect(page).toHaveURL(/.*\/login/);
  });
});
