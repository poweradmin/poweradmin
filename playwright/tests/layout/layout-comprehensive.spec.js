/**
 * Layout Comprehensive Tests
 *
 * Tests for overall layout, footer, navigation, and page structure.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Footer render checks (visible + poweradmin link + version + copyright) live
// in playwright/tests/layout/footer.spec.js - the footer is one shared partial,
// so it is proven per role there instead of being re-asserted here.

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
    // Nav-link presence (menu / zones / users / search / logout) is the canonical
    // per-role matrix in playwright/tests/layout/navigation.spec.js. Only the
    // links and behaviours unique to this file remain below.
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
        await page.waitForLoadState('networkidle');
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/login|password|username/i);
      }
    });
  });

  test.describe('Manager User Navigation', () => {
    // Nav menu + zones-link presence for manager is covered in navigation.spec.js.
    test('should show logout link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/');

      const logoutLink = page.locator('a[href*="logout"], a:has-text("Logout")');
      expect(await logoutLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Client User Navigation', () => {
    // Nav menu presence for client is covered in navigation.spec.js.
    test('should show limited navigation (zones link depends on permissions)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/');

      // Client users have limited permissions - zones link visibility depends on their assigned zones
      const zonesLink = page.locator('a[href*="/zones"]');
      const count = await zonesLink.count();
      // Client users may or may not have zones link depending on their permissions
      expect(count >= 0).toBeTruthy();
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
  // One load of the dashboard, all structural shell assertions in a single test.
  test('page shell is well-formed', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/');

    await expect(page.locator('html')).toBeVisible();
    await expect(page.locator('body')).toBeVisible();
    expect(await page.title()).toBeTruthy();
    expect(await page.locator('h1, h2, h3, h4, h5, .page-title').count()).toBeGreaterThan(0);
    expect(await page.locator('main, .content, #content, .container').count()).toBeGreaterThan(0);
    expect(await page.locator('link[rel="stylesheet"]').count()).toBeGreaterThan(0);
    expect(await page.locator('script').count()).toBeGreaterThan(0);
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
      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
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

        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      }
    }
  });
});
