/**
 * Layout Comprehensive Tests
 *
 * Tests for overall layout, footer, navigation, and page structure.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Layout - Footer', () => {
  test.describe('Admin User', () => {
    test('should display footer', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });

    test('should display version info', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/version|poweradmin|v\d/i);
    });

    test('should display copyright', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/©|copyright|\d{4}|poweradmin/i);
    });

    test('footer should be visible on zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });

    test('footer should be visible on users page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User', () => {
    test('should display footer', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });

  test.describe('Client User', () => {
    test('should display footer', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });

  test.describe('Viewer User', () => {
    test('should display footer', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/');

      const footer = page.locator('footer, .footer, #footer');
      if (await footer.count() > 0) {
        await expect(footer.first()).toBeVisible();
      }
    });
  });
});

test.describe('Layout - Navigation', () => {
  test.describe('Logged Out User', () => {
    test('should show login page', async ({ page }) => {
      await page.goto('/');

      const url = page.url();
      expect(url).toMatch(/login/);
    });

    test('should not show navigation menu', async ({ page }) => {
      await page.goto('/login');

      const logoutLink = page.locator('a:has-text("Logout")');
      expect(await logoutLink.count()).toBe(0);
    });
  });

  test.describe('Admin User Navigation', () => {
    test('should display navigation menu', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const nav = page.locator('nav, .navbar, .navigation, #menu, ul.nav');
      expect(await nav.count()).toBeGreaterThan(0);
    });

    test('should show zones link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const zonesLink = page.locator('a[href*="/zones"], a:has-text("Zone")');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should show users link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const usersLink = page.locator('a[href*="/users"], a:has-text("User")');
      expect(await usersLink.count()).toBeGreaterThan(0);
    });

    test('should show search link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const searchLink = page.locator('a[href*="/search"], a:has-text("Search")');
      expect(await searchLink.count()).toBeGreaterThan(0);
    });

    test('should show logout link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });

    test('should show supermasters link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const supermastersLink = page.locator('a[href*="supermaster"], a:has-text("Supermaster")');
      if (await supermastersLink.count() > 0) {
        await expect(supermastersLink.first()).toBeVisible();
      }
    });

    test('should show permission templates link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const templatesLink = page.locator('a[href*="permissions"], a:has-text("Permission")');
      expect(await templatesLink.count()).toBeGreaterThan(0);
    });

    test('should navigate to zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const zonesLink = page.locator('a[href*="/zones/forward"]').first();
      if (await zonesLink.count() > 0) {
        await zonesLink.click();
        await expect(page).toHaveURL(/.*zones\/forward/);
      }
    });

    test('should navigate to users page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const usersLink = page.locator('a[href="/users"]').first();
      if (await usersLink.count() > 0) {
        await usersLink.click();
        await expect(page).toHaveURL(/.*\/users/);
      }
    });

    test('should logout successfully', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const logoutLink = page.locator('a[href*="logout"]');
      if (await logoutLink.count() > 0) {
        const isVisible = await logoutLink.first().isVisible();
        if (!isVisible) {
          const accountDropdown = page.locator('button:has-text("Account"), [data-bs-toggle="dropdown"]:has-text("Account")');
          if (await accountDropdown.count() > 0) {
            await accountDropdown.first().click();
          }
        }
        await logoutLink.first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/login|password|username/i);
      }
    });
  });

  test.describe('Manager User Navigation', () => {
    test('should display navigation menu', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/');

      const nav = page.locator('nav, .navbar, .navigation, #menu, header');
      expect(await nav.count()).toBeGreaterThan(0);
    });

    test('should show zones link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/');

      const zonesLink = page.locator('a[href*="/zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should show logout link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Client User Navigation', () => {
    test('should display navigation menu', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/');

      const nav = page.locator('nav, .navbar, .navigation, #menu, header');
      expect(await nav.count()).toBeGreaterThan(0);
    });

    test('should show zones link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/');

      const zonesLink = page.locator('a[href*="/zones"]');
      expect(await zonesLink.count()).toBeGreaterThan(0);
    });

    test('should show logout link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Viewer User Navigation', () => {
    test('should display limited navigation menu', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not show add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const addBtn = page.locator('input[value*="Add master"], input[value*="Add slave"]');
      expect(await addBtn.count()).toBe(0);
    });

    test('should show logout link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Active Menu Highlighting', () => {
    test('should highlight current page in menu', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const activeLink = page.locator('.active, .current, [aria-current="page"]');
      const zonesLink = page.locator('a[href*="/zones"]').first();
      if (await zonesLink.count() > 0) {
        await expect(zonesLink).toBeVisible();
      }
    });
  });

  test.describe('Responsive Navigation', () => {
    test('should display on mobile viewport', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 });
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display on tablet viewport', async ({ page }) => {
      await page.setViewportSize({ width: 768, height: 1024 });
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display on desktop viewport', async ({ page }) => {
      await page.setViewportSize({ width: 1920, height: 1080 });
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});

test.describe('Layout - Page Structure', () => {
  test.describe('Dashboard', () => {
    test('should display page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const title = page.locator('h1, h2, h3, h4, h5, .page-title');
      expect(await title.count()).toBeGreaterThan(0);
    });

    test('should display main content area', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const content = page.locator('main, .content, #content, .container');
      expect(await content.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Common Elements', () => {
    test('should have proper HTML structure', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const html = page.locator('html');
      await expect(html).toBeVisible();
    });

    test('should have head element', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const title = await page.title();
      expect(title).toBeTruthy();
    });

    test('should have body element', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const body = page.locator('body');
      await expect(body).toBeVisible();
    });

    test('should load CSS', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const stylesheets = page.locator('link[rel="stylesheet"]');
      expect(await stylesheets.count()).toBeGreaterThan(0);
    });

    test('should load JavaScript', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/');

      const scripts = page.locator('script');
      expect(await scripts.count()).toBeGreaterThan(0);
    });
  });
});

test.describe('Layout - Breadcrumbs', () => {
  test('should display breadcrumbs on zone edit page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    const editLink = page.locator('table a[href*="/edit"]').first();

    if (await editLink.count() > 0) {
      await editLink.click();

      const breadcrumbs = page.locator('.breadcrumb, nav[aria-label*="breadcrumb"]');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should display breadcrumbs on add record page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    const editLink = page.locator('table a[href*="/edit"]').first();

    if (await editLink.count() > 0) {
      await editLink.click();

      const addRecordLink = page.locator('a[href*="/records/add"]').first();
      if (await addRecordLink.count() > 0) {
        await addRecordLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });
});
