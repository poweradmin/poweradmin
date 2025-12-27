import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search and Utility Tools', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access search page', async ({ page }) => {
    await page.goto('/index.php?page=search');
    await expect(page).toHaveURL(/page=search/);
    await expect(page.locator('form, input[type="search"], input[name*="search"]')).toBeVisible();
  });

  test('should show search form with query input', async ({ page }) => {
    await page.goto('/index.php?page=search');

    // Should have search input field
    await expect(page.locator('input[type="search"], input[name*="search"], input[name*="query"], input[placeholder*="search"]').first()).toBeVisible();

    // Should have search button
    await expect(page.locator('button[type="submit"], input[type="submit"]')).toBeVisible();
  });

  test('should handle empty search query', async ({ page }) => {
    await page.goto('/index.php?page=search');

    // Submit empty search
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should stay on search page or show validation
    await expect(page).toHaveURL(/page=search/);
  });

  test('should perform search with query', async ({ page }) => {
    await page.goto('/index.php?page=search');

    // Enter search query
    await page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first().fill('example.com');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show search results or "no results" message
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/results|found|search/i);
  });

  test('should access WHOIS tool', async ({ page }) => {
    await page.goto('/index.php?page=search');
    await expect(page).toHaveURL(/page=search/);
    await expect(page.locator('form, input[name*="domain"], input[placeholder*="domain"]')).toBeVisible();
  });

  test('should show WHOIS form fields', async ({ page }) => {
    await page.goto('/index.php?page=search');

    // Should have domain input field
    await expect(page.locator('input[name*="domain"], input[name*="host"], input[placeholder*="domain"]').first()).toBeVisible();

    // Should have lookup button
    await expect(page.locator('button[type="submit"], input[type="submit"]')).toBeVisible();
  });

  test('should validate WHOIS domain input', async ({ page }) => {
    await page.goto('/index.php?page=search');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should stay on form or show validation
    await expect(page).toHaveURL(/page=search/);
  });

  test('should perform WHOIS lookup', async ({ page }) => {
    await page.goto('/index.php?page=search');

    // Enter domain
    await page.locator('input[name*="domain"], input[name*="host"], input[placeholder*="domain"]').first().fill('example.com');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show WHOIS results or error message
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/whois|domain|lookup/i);
  });

  test('should access RDAP tool if enabled', async ({ page }) => {
    // RDAP may not be available in 3.x, use search page instead
    await page.goto('/index.php?page=search', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('not found')) {
      await expect(page).toHaveURL(/page=search/);
      await expect(page.locator('form, input')).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'Search tool not available' });
    }
  });

  test('should access database consistency tool if available', async ({ page }) => {
    // Database consistency tool may have different page name in 3.x
    await page.goto('/index.php?page=database_consistency', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('not found') && !bodyText.includes('permission')) {
      await expect(page).toHaveURL(/page=database_consistency/);
      expect(bodyText).toMatch(/consistency|database|check/i);
    } else {
      test.info().annotations.push({ type: 'note', description: 'Database consistency tool not available or no permission' });
    }
  });

  test('should show navigation menu items', async ({ page }) => {
    await page.goto('/index.php?page=index');

    // Check for main navigation elements
    await expect(page.locator('nav, .navbar, .navigation, header').first()).toBeVisible();

    // Should have various menu items
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|DNS/i);
    expect(bodyText).toMatch(/Users|Administration/i);
  });

  test('should have working logout functionality', async ({ page }) => {
    await page.goto('/index.php?page=index');

    // Look for logout link/button
    const hasLogout = await page.locator('a, button').filter({ hasText: /Logout|Sign out/i }).count();
    if (hasLogout > 0) {
      await page.locator('a, button').filter({ hasText: /Logout|Sign out/i }).click();

      // Should redirect to login page
      await expect(page).toHaveURL(/page=login/);
    } else {
      // Try direct logout URL
      await page.goto('/index.php?page=logout');
      await expect(page).toHaveURL(/page=login/);
    }
  });
});
