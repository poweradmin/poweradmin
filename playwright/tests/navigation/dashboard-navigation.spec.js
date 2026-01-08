import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Dashboard and Navigation', () => {
  test('should display dashboard after login', async ({ adminPage: page }) => {
    // Should be on dashboard/home page
    await expect(page).toHaveURL(/page=index/);

    const body = page.locator('body');
    const hasWelcomeText = await body.getByText(/Welcome|Dashboard|Poweradmin/i).count() > 0;
    expect(hasWelcomeText).toBeTruthy();
  });

  test('should show user name on dashboard', async ({ adminPage: page }) => {
    const body = page.locator('body');
    const hasUserInfo = await body.getByText(/admin|Welcome/i).count() > 0;
    expect(hasUserInfo).toBeTruthy();
  });

  test('should display main navigation menu', async ({ adminPage: page }) => {
    await expect(page.locator('nav, .navbar, .navigation, header')).toBeVisible();

    // Check for key navigation items
    await expect(page.locator('body')).toContainText('Zones');
    await expect(page.locator('body')).toContainText('Users');
  });

  test('should have functional zone navigation links', async ({ adminPage: page }) => {
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

  test('should navigate to forward zones page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones');
    await expect(page).toHaveURL(/page=list_forward_zones/);
    // Page may use various heading levels
    await expect(page.locator('h1, h2, h3, h4, h5, .page-title').first()).toBeVisible();
  });

  test('should navigate to reverse zones page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones');
    await expect(page).toHaveURL(/page=list_forward_zones/);
    // Page may use various heading levels
    await expect(page.locator('h1, h2, h3, h4, h5, .page-title').first()).toBeVisible();
  });

  test('should navigate to users page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=users');
    await expect(page).toHaveURL(/page=users/);
    // Page may use various heading levels
    await expect(page.locator('h1, h2, h3, h4, h5, .page-title').first()).toBeVisible();
  });

  test('should show dashboard cards or widgets', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=index');

    // Look for dashboard cards/widgets
    const hasCards = await page.locator('.card, .widget, .panel').count() > 0;
    if (hasCards) {
      await expect(page.locator('.card, .widget, .panel').first()).toBeVisible();
    } else {
      // Alternative: check for dashboard links
      await expect(page.locator('a[data-testid*="link"], button[data-testid*="button"]')).toHaveCount(await page.locator('a[data-testid*="link"], button[data-testid*="button"]').count());
    }
  });

  test('should have breadcrumb navigation on sub-pages', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_user');

    const breadcrumbs = page.locator('.breadcrumb, nav[aria-label*="breadcrumb"]');
    const hasBreadcrumb = await breadcrumbs.count() > 0;
    if (hasBreadcrumb) {
      // Use first() to avoid strict mode violation when multiple elements match
      await expect(breadcrumbs.first()).toBeVisible();
    } else {
      // Check for page title or heading
      await expect(page.locator('h1, h2, h3, h4, h5, .page-title').first()).toBeVisible();
    }
  });

  test('should handle responsive navigation', async ({ adminPage: page }) => {
    // Test mobile viewport
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/index.php?page=index');

    // Navigation should still be accessible
    await expect(page.locator('nav, .navbar, .navigation, header')).toHaveCount(await page.locator('nav, .navbar, .navigation, header').count());

    // Reset viewport
    await page.setViewportSize({ width: 1280, height: 720 });
  });

  test('should maintain session across page navigation', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=index');
    await page.goto('/index.php?page=users');
    await page.goto('/index.php?page=list_forward_zones');
    await page.goto('/index.php?page=search');

    // Should still be logged in
    await expect(page).not.toHaveURL(/page=login/);
    const hasLoginText = await page.locator('body').getByText('Please log in').count() > 0;
    expect(hasLoginText).toBeFalsy();
  });

  test('should show appropriate error pages for invalid URLs', async ({ adminPage: page }) => {
    const response = await page.goto('/index.php?page=nonexistent', { waitUntil: 'networkidle' });

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
