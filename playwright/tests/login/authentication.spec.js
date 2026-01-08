import { test, expect } from '../../fixtures/test-fixtures.js';
import { login, loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Login Authentication', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/index.php?page=login');
  });

  test.describe('Successful Login - All User Types', () => {
    test('should login admin user and redirect to dashboard', async ({ page }) => {
      await login(page, users.admin.username, users.admin.password);
      // Dashboard URL: /index.php or /index.php?page=index (may have additional params)
      await expect(page).toHaveURL(/index\.php($|\?page=index)/);
    });

    test('should login manager user and redirect to dashboard', async ({ page }) => {
      await login(page, users.manager.username, users.manager.password);
      await expect(page).toHaveURL(/index\.php($|\?page=index)/);
    });

    test('should login client user and redirect to dashboard', async ({ page }) => {
      await login(page, users.client.username, users.client.password);
      await expect(page).toHaveURL(/index\.php($|\?page=index)/);
    });

    test('should login viewer user and redirect to dashboard', async ({ page }) => {
      await login(page, users.viewer.username, users.viewer.password);
      await expect(page).toHaveURL(/index\.php($|\?page=index)/);
    });

    test('should login noperm user and redirect to dashboard', async ({ page }) => {
      await login(page, users.noperm.username, users.noperm.password);
      await expect(page).toHaveURL(/index\.php($|\?page=index)/);
    });
  });

  test.describe('Failed Login Attempts', () => {
    test('should remain on login page for invalid credentials', async ({ page }) => {
      await login(page, users.invalidUser.username, users.invalidUser.password);
      await expect(page).toHaveURL(/page=login/);
    });

    test('should display error message for invalid login', async ({ page }) => {
      await page.getByLabel('Username').fill(users.invalidUser.username);
      await page.getByLabel('Password').fill(users.invalidUser.password);
      await page.getByRole('button', { name: /log in/i }).click();
      // Check for error message or alert
      const hasError = await page.locator('.alert-danger, .error, [data-testid="session-error"]').first().isVisible().catch(() => false);
      const bodyText = await page.locator('body').textContent();
      expect(hasError || bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('invalid')).toBeTruthy();
    });

    test('should not allow inactive user to login', async ({ page }) => {
      await login(page, users.inactive.username, users.inactive.password);
      await expect(page).toHaveURL(/page=login/);
    });

    test('should not login with correct username but wrong password', async ({ page }) => {
      await login(page, users.admin.username, 'wrongpassword');
      await expect(page).toHaveURL(/page=login/);
    });

    test('should not login with wrong username but correct password', async ({ page }) => {
      await login(page, 'wronguser', users.admin.password);
      await expect(page).toHaveURL(/page=login/);
    });

    test('should not login with empty password', async ({ page }) => {
      await page.getByLabel('Username').fill(users.admin.username);
      await page.getByRole('button', { name: /log in/i }).click();
      await expect(page).toHaveURL(/page=login/);
    });
  });

  test.describe('Session Handling', () => {
    test('should maintain session after login', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should redirect to login when accessing protected page without session', async ({ page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      await expect(page).toHaveURL(/page=login/);
    });
  });
});

test.describe('User Permissions After Login', () => {
  test.describe('Admin User Permissions', () => {
    test('should have access to dashboard', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should have access to user administration', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      await expect(page).toHaveURL(/page=users/);
    });

    test('should have access to permission templates', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      await expect(page).toHaveURL(/page=list_perm_templ/);
      // Should show the page without being redirected to login
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/permission|template/i);
    });

    test('should have access to add permission template', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      await expect(page).toHaveURL(/page=add_perm_templ/);
    });

    test('should have access to zone list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/page=list_forward_zones/);
    });

    test('should have access to add master zone', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      await expect(page).toHaveURL(/page=add_zone_master/);
    });

    test('should have access to add slave zone', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_zone_slave');
      await expect(page).toHaveURL(/page=add_zone_slave/);
    });

    test('should have access to search', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should have access to add user', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await expect(page).toHaveURL(/page=add_user/);
    });
  });

  test.describe('Manager User Permissions', () => {
    test('should have access to dashboard', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should have access to zone list', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/page=list_forward_zones/);
    });

    test('should have access to add master zone', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      await expect(page).toHaveURL(/page=add_zone_master/);
    });

    test('should have access to add slave zone', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=add_zone_slave');
      await expect(page).toHaveURL(/page=add_zone_slave/);
    });

    test('should have access to search', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should NOT have access to permission templates', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      // Should be redirected or show error
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       bodyText.toLowerCase().includes('not allowed');
      const redirectedToLogin = page.url().includes('page=login');
      expect(hasError || redirectedToLogin || !page.url().includes('list_perm_templ')).toBeTruthy();
    });

    test('should NOT have access to add user', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=add_user');
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
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should have access to zone list (own zones)', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/page=list_forward_zones/);
    });

    test('should have access to search', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should NOT have access to add master zone', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should NOT have access to permission templates', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const bodyText = await page.locator('body').textContent();
      // Client should see error message or be redirected
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('permission') ||
                       bodyText.toLowerCase().includes('denied');
      const isRedirected = page.url().includes('page=login');
      const noTable = await page.locator('table').count() === 0;
      expect(hasError || isRedirected || noTable).toBeTruthy();
    });
  });

  test.describe('Viewer User Permissions', () => {
    test('should have access to dashboard', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should have access to zone list (view only)', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await expect(page).toHaveURL(/page=list_forward_zones/);
    });

    test('should have access to search', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should NOT have access to add master zone', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should NOT have access to add slave zone', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=add_zone_slave');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });
  });

  test.describe('No Permission User Permissions', () => {
    test('should have access to dashboard', async ({ nopermPage: page }) => {
      await page.goto('/index.php?page=index');
      await expect(page).toHaveURL(/page=index/);
    });

    test('should NOT have access to add master zone', async ({ nopermPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should NOT have access to permission templates', async ({ nopermPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      const bodyText = await page.locator('body').textContent();
      const hasPermTable = bodyText.toLowerCase().includes('permission template') && !bodyText.toLowerCase().includes('error');
      expect(!hasPermTable || page.url().includes('page=login')).toBeTruthy();
    });
  });
});

test.describe('Logout Functionality', () => {
  test('should logout admin user successfully', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=logout');
    await expect(page).toHaveURL(/page=login/);
  });

  test('should logout manager user successfully', async ({ managerPage: page }) => {
    await page.goto('/index.php?page=logout');
    await expect(page).toHaveURL(/page=login/);
  });

  test('should not be able to access protected pages after logout', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=logout');
    await page.goto('/index.php?page=list_perm_templ');
    await expect(page).toHaveURL(/page=login/);
  });
});
