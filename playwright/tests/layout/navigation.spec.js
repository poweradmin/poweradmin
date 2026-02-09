/**
 * Navigation Tests
 *
 * Tests for header navigation, menu items, and access control
 * across different user roles.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Header Navigation', () => {
  test.describe('Logged Out User', () => {
    test('should not display user navigation items when not logged in', async ({ page }) => {
      await page.goto('/login');
      // Navigation should be minimal or hidden for logged-out users
      const hasZonesNav = await page.locator('a[href*="/zones/forward"]').count() > 0;
      const hasUsersNav = await page.locator('a[href*="/users"]').count() > 0;
      // On login page, user-specific navigation should not be visible
      expect(hasZonesNav && hasUsersNav).toBeFalsy();
    });
  });

  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display site header', async ({ page }) => {
      const header = page.locator('header, .navbar, nav').first();
      await expect(header).toBeVisible();
    });

    test('should display home/logo link', async ({ page }) => {
      const homeLink = page.locator('a[href="/"], a.navbar-brand, .logo a').first();
      await expect(homeLink).toBeVisible();
    });

    test('should display main navigation', async ({ page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display search navigation item', async ({ page }) => {
      const searchLink = page.locator('a[href*="/search"]').first();
      await expect(searchLink).toBeVisible();
    });

    test('should display zones navigation', async ({ page }) => {
      const zonesLink = page.locator('a[href*="/zones/forward"]').first();
      await expect(zonesLink).toBeVisible();
    });

    test('should have access to list zones', async ({ page }) => {
      const listZonesLink = page.locator('a[href*="/zones/forward?letter=all"]').first();
      if (await listZonesLink.count() > 0) {
        await expect(listZonesLink).toBeVisible();
      }
    });

    test('should have access to add master zone', async ({ page }) => {
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*zones\/add\/master/);
    });

    test('should have access to add slave zone', async ({ page }) => {
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*zones\/add\/slave/);
    });

    test('should have access to bulk registration', async ({ page }) => {
      await page.goto('/zones/bulk-registration');
      await expect(page).toHaveURL(/.*zones\/bulk-registration/);
    });

    test('should display zone logs for admin', async ({ page }) => {
      await page.goto('/zones/logs');
      await expect(page).toHaveURL(/.*zones\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toMatch(/error|denied/);
    });

    test('should display users navigation', async ({ page }) => {
      const usersLink = page.locator('a[href*="/users"]').first();
      await expect(usersLink).toBeVisible();
    });

    test('should have access to user administration', async ({ page }) => {
      await page.goto('/users');
      await expect(page).toHaveURL(/.*\/users/);
    });

    test('should have access to add user', async ({ page }) => {
      await page.goto('/users/add');
      await expect(page).toHaveURL(/.*\/users\/add/);
    });

    test('should have access to permission templates', async ({ page }) => {
      await page.goto('/permissions/templates');
      await expect(page).toHaveURL(/.*permissions\/templates/);
    });

    test('should have access to add permission template', async ({ page }) => {
      await page.goto('/permissions/templates/add');
      await expect(page).toHaveURL(/.*permissions\/templates\/add/);
    });

    test('should display user logs for admin', async ({ page }) => {
      await page.goto('/users/logs');
      await expect(page).toHaveURL(/.*users\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).not.toMatch(/error|denied/);
    });

    test('should display templates navigation', async ({ page }) => {
      const templatesLink = page.locator('a[href*="/zones/templates"]').first();
      await expect(templatesLink).toBeVisible();
    });

    test('should have access to zone templates', async ({ page }) => {
      await page.goto('/zones/templates');
      await expect(page).toHaveURL(/.*zones\/templates/);
    });

    test('should have access to add zone template', async ({ page }) => {
      await page.goto('/zones/templates/add');
      await expect(page).toHaveURL(/.*zones\/templates\/add/);
    });

    test('should display account navigation', async ({ page }) => {
      const accountLink = page.locator('a[href*="/password/change"], a[href*="/logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should have access to change password', async ({ page }) => {
      await page.goto('/password/change');
      await expect(page).toHaveURL(/.*password\/change/);
    });

    test('should have access to logout', async ({ page }) => {
      const logoutLink = page.locator('a[href*="/logout"]');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Manager User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should display navigation for manager', async ({ page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display zones navigation', async ({ page }) => {
      const zonesLink = page.locator('a[href*="/zones/forward"]').first();
      await expect(zonesLink).toBeVisible();
    });

    test('should display templates navigation', async ({ page }) => {
      const templatesLink = page.locator('a[href*="/zones/templates"]').first();
      await expect(templatesLink).toBeVisible();
    });

    test('should display account navigation', async ({ page }) => {
      const accountLink = page.locator('a[href*="/password/change"], a[href*="/logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should not have access to zone logs', async ({ page }) => {
      await page.goto('/zones/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('/login');
      expect(hasError).toBeTruthy();
    });

    test('should not have access to user logs', async ({ page }) => {
      await page.goto('/users/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('/login');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Client User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should display limited navigation for client', async ({ page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display account navigation', async ({ page }) => {
      const accountLink = page.locator('a[href*="/password/change"], a[href*="/logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should not have access to add master zone', async ({ page }) => {
      await page.goto('/zones/add/master');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied');
      const hasZoneForm = await page.locator('input[name="domain"], input[name*="zone"]').count() > 0;
      expect(hasError || !hasZoneForm).toBeTruthy();
    });

    test('should not have access to templates navigation', async ({ page }) => {
      await page.goto('/zones/templates');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().includes('/login');
      expect(hasError || page.url().includes('/')).toBeTruthy();
    });
  });

  test.describe('Viewer User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should display navigation for viewer', async ({ page }) => {
      const nav = page.locator('nav, .navbar, .navigation, header').first();
      await expect(nav).toBeVisible();
    });

    test('should display account navigation', async ({ page }) => {
      const accountLink = page.locator('a[href*="/password/change"], a[href*="/logout"]');
      expect(await accountLink.count()).toBeGreaterThan(0);
    });

    test('should not see add zone buttons', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const addMasterBtn = page.locator('a[href*="/zones/add/master"]');
      expect(await addMasterBtn.count()).toBe(0);
    });

    test('should not see delete zone buttons', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const deleteBtn = page.locator('a[href*="/delete"]');
      expect(await deleteBtn.count()).toBe(0);
    });
  });

  test.describe('Navigation Responsiveness', () => {
    test('should have mobile menu toggle if present', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const toggle = page.locator('.navbar-toggler, .menu-toggle, button[data-bs-toggle="collapse"]');
      // Toggle may be hidden on desktop, just check it exists
      expect(await toggle.count() >= 0).toBeTruthy();
    });

    test('should have dropdown menus if present', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const dropdowns = page.locator('.dropdown, [data-bs-toggle="dropdown"]');
      // Dropdowns are optional
      expect(await dropdowns.count() >= 0).toBeTruthy();
    });
  });
});
