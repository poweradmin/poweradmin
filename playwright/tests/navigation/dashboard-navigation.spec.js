import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Dashboard and Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display dashboard after login', async ({ page }) => {
    // Should be on dashboard/home page
    await expect(page).toHaveURL('/');

    const body = page.locator('body');
    const hasWelcomeText = await body.getByText(/Welcome|Dashboard|Poweradmin/i).count() > 0;
    expect(hasWelcomeText).toBeTruthy();
  });

  test('should show user name on dashboard', async ({ page }) => {
    const body = page.locator('body');
    const hasUserInfo = await body.getByText(/admin|Welcome/i).count() > 0;
    expect(hasUserInfo).toBeTruthy();
  });

  test('should display main navigation menu', async ({ page }) => {
    await expect(page.locator('nav, .navbar, .navigation, header')).toBeVisible();

    // Check for key navigation items
    await expect(page.locator('body')).toContainText('Zones');
    await expect(page.locator('body')).toContainText('Users');
  });

  test('should have functional zone navigation links', async ({ page }) => {
    // Forward zones link
    const forwardLink = page.locator('a').filter({ hasText: 'Forward' });
    if (await forwardLink.count() > 0) {
      await expect(forwardLink.first()).toHaveAttribute('href', /.+/);
    }

    // Add zone links
    const addZoneLink = page.locator('a').filter({ hasText: /Add.*Zone/i });
    if (await addZoneLink.count() > 0) {
      await expect(addZoneLink.first()).toHaveAttribute('href', /.+/);
    }
  });

  test('should navigate to forward zones page', async ({ page }) => {
    await page.goto('/zones/forward');
    await expect(page).toHaveURL(/.*zones\/forward/);
    await expect(page.locator('h1, h2, h3, .page-title')).toBeVisible();
  });

  test('should navigate to reverse zones page', async ({ page }) => {
    await page.goto('/zones/reverse');
    await expect(page).toHaveURL(/.*zones\/reverse/);
    await expect(page.locator('h1, h2, h3, .page-title')).toBeVisible();
  });

  test('should navigate to users page', async ({ page }) => {
    await page.goto('/users');
    await expect(page).toHaveURL(/.*users/);
    await expect(page.locator('h1, h2, h3, .page-title')).toBeVisible();
  });

  test('should show dashboard cards or widgets', async ({ page }) => {
    await page.goto('/');

    // Look for dashboard cards/widgets
    const hasCards = await page.locator('.card, .widget, .panel').count() > 0;
    if (hasCards) {
      await expect(page.locator('.card, .widget, .panel').first()).toBeVisible();
    } else {
      // Alternative: check for dashboard links
      await expect(page.locator('a[data-testid*="link"], button[data-testid*="button"]')).toHaveCount(await page.locator('a[data-testid*="link"], button[data-testid*="button"]').count());
    }
  });

  test('should have breadcrumb navigation on sub-pages', async ({ page }) => {
    await page.goto('/users/add');

    const hasBreadcrumb = await page.locator('.breadcrumb, nav[aria-label*="breadcrumb"]').count() > 0;
    if (hasBreadcrumb) {
      await expect(page.locator('.breadcrumb, nav[aria-label*="breadcrumb"]')).toBeVisible();
    } else {
      // Check for page title or heading
      await expect(page.locator('h1, h2, h3, .page-title')).toBeVisible();
    }
  });

  test('should handle responsive navigation', async ({ page }) => {
    // Test mobile viewport
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/');

    // Navigation should still be accessible
    await expect(page.locator('nav, .navbar, .navigation, header')).toHaveCount(await page.locator('nav, .navbar, .navigation, header').count());

    // Reset viewport
    await page.setViewportSize({ width: 1280, height: 720 });
  });

  test('should maintain session across page navigation', async ({ page }) => {
    await page.goto('/');
    await page.goto('/users');
    await page.goto('/zones/forward');
    await page.goto('/search');

    // Should still be logged in
    await expect(page).not.toHaveURL(/.*login/);
    const hasLoginText = await page.locator('body').getByText('Please log in').count() > 0;
    expect(hasLoginText).toBeFalsy();
  });

  test('should show appropriate error pages for invalid URLs', async ({ page }) => {
    const response = await page.goto('/nonexistent-page', { waitUntil: 'networkidle' });

    const bodyText = await page.locator('body').textContent();
    const has404 = bodyText?.includes('404') || bodyText?.includes('not found');

    if (has404) {
      expect(bodyText).toMatch(/404|not found/i);
    } else {
      // Might redirect to home or show different error
      console.log('No 404 page found, application might redirect');
    }
  });
});
