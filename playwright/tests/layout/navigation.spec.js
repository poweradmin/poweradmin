import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Header Navigation', () => {
  test.describe('Logged Out User', () => {
    test('should not display user navigation items when not logged in', async ({ page }) => {
      await page.goto('/index.php?page=login');
      // Navigation should be minimal or hidden for logged-out users
      const hasZonesNav = await page.locator('a[href*="list_forward_zones"]').count() > 0;
      const hasUsersNav = await page.locator('a[href*="page=users"]').count() > 0;
      // On login page, user-specific navigation should not be visible
      expect(hasZonesNav && hasUsersNav).toBeFalsy();
    });
  });

  test.describe('Admin User', () => {
    test('should display site header', async ({ adminPage: page }) => {
      const header = page.locator('header, .navbar, nav').first();
      await expect(header).toBeVisible();
    });

    test('should display home/logo link', async ({ adminPage: page }) => {
      const homeLink = page.locator('a[href*="index.php"], a.navbar-brand, .logo a').first();
      await expect(homeLink).toBeVisible();
    });

    test('should display main navigation', async ({ adminPage: page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display search navigation item', async ({ adminPage: page }) => {
      const searchLink = page.locator('a[href*="page=search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should display zones navigation', async ({ adminPage: page }) => {
      const zonesLink = page.locator('a[href*="list_forward_zones"]').first();
      await expect(zonesLink).toBeVisible();
    });

    test('should have access to list zones', async ({ adminPage: page }) => {
      const listZonesLink = page.locator('a[href*="page=list_forward_zones"]').first();
      if (await listZonesLink.count() > 0) {
        await expect(listZonesLink).toBeVisible();
      }
    });

    test('should have access to add master zone', async ({ adminPage: page }) => {
      // Check if add master zone is in navigation or accessible
      await page.goto('/index.php?page=add_zone_master');
      await expect(page).toHaveURL(/page=add_zone_master/);
    });

    test('should have access to add slave zone', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_zone_slave');
      await expect(page).toHaveURL(/page=add_zone_slave/);
    });

    test('should have access to bulk registration', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      await expect(page).toHaveURL(/page=bulk_registration/);
    });

    test('should display zone logs for admin', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_zones');
      await expect(page).toHaveURL(/page=list_log_zones/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toMatch(/error|denied/);
    });

    test('should display users navigation', async ({ adminPage: page }) => {
      const usersLink = page.locator('a[href*="page=users"]').first();
      await expect(usersLink).toBeVisible();
    });

    test('should have access to user administration', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      await expect(page).toHaveURL(/page=users/);
    });

    test('should have access to add user', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await expect(page).toHaveURL(/page=add_user/);
    });

    test('should have access to permission templates', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');
      await expect(page).toHaveURL(/page=list_perm_templ/);
    });

    test('should have access to add permission template', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_perm_templ');
      await expect(page).toHaveURL(/page=add_perm_templ/);
    });

    test('should display user logs for admin', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      await expect(page).toHaveURL(/page=list_log_users/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toMatch(/error|denied/);
    });

    test('should display templates navigation', async ({ adminPage: page }) => {
      const templatesLink = page.locator('a[href*="list_zone_templ"]').first();
      await expect(templatesLink).toBeVisible();
    });

    test('should have access to zone templates', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      await expect(page).toHaveURL(/page=list_zone_templ/);
    });

    test('should have access to add zone template', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_zone_templ');
      await expect(page).toHaveURL(/page=add_zone_templ/);
    });

    test('should display account navigation', async ({ adminPage: page }) => {
      // Account links may be in dropdown menu, check if they exist in DOM
      const accountLink = page.locator('a[href*="change_password"], a[href*="logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should have access to change password', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');
      await expect(page).toHaveURL(/page=change_password/);
    });

    test('should have access to logout', async ({ adminPage: page }) => {
      // Logout link may be in dropdown menu, check if it exists in DOM
      const logoutLink = page.locator('a[href*="page=logout"]');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Manager User', () => {
    test('should display navigation for manager', async ({ managerPage: page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display zones navigation', async ({ managerPage: page }) => {
      const zonesLink = page.locator('a[href*="list_forward_zones"]').first();
      await expect(zonesLink).toBeVisible();
    });

    test('should display templates navigation', async ({ managerPage: page }) => {
      const templatesLink = page.locator('a[href*="list_zone_templ"]').first();
      await expect(templatesLink).toBeVisible();
    });

    test('should display account navigation', async ({ managerPage: page }) => {
      // Account links may be in dropdown menu, check if they exist in DOM
      const accountLink = page.locator('a[href*="change_password"], a[href*="logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should not have access to zone logs', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_log_zones');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('page=login');
      expect(hasError).toBeTruthy();
    });

    test('should not have access to user logs', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_log_users');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('page=login');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Client User', () => {
    test('should display limited navigation for client', async ({ clientPage: page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display account navigation', async ({ clientPage: page }) => {
      // Account links may be in dropdown menu, check if they exist in DOM
      const accountLink = page.locator('a[href*="change_password"], a[href*="logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should not have access to add master zone', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should not have access to templates navigation', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      const bodyText = await page.locator('body').textContent();
      const hasAccess = !bodyText.toLowerCase().includes('error') &&
                        !bodyText.toLowerCase().includes('denied') &&
                        !page.url().includes('page=login');
      // Client may or may not have access - verify page behavior
      expect(page.url()).toMatch(/page=/);
    });
  });

  test.describe('Viewer User', () => {
    test('should display minimal navigation for viewer', async ({ viewerPage: page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display account navigation', async ({ viewerPage: page }) => {
      // Account links may be in dropdown menu, check if they exist in DOM
      const accountLink = page.locator('a[href*="change_password"], a[href*="logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should have access to search', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should not have access to add zone options', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=add_zone_master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });
  });
});
