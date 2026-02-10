import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search and Utility Tools', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access search page', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/.*search/);

    const bodyText = await page.locator('body').textContent();
    expect(bodyText.toLowerCase()).toMatch(/search|dns|query/i);
  });

  test('should show search form with query input', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    // Should have search input field
    const searchInput = page.locator('input[type="search"], input[name*="search"], input[name*="query"], input[placeholder*="search"], input[placeholder*="domain"]').first();
    await expect(searchInput).toBeVisible();

    // Should have search button
    await expect(page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first()).toBeVisible();
  });

  test('should handle empty search query', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    // Submit empty search
    await page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first().click();
    await page.waitForLoadState('networkidle');

    // Should stay on search page or show validation
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should perform search with query', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    // Enter search query
    const searchInput = page.locator('input[type="search"], input[name*="search"], input[name*="query"], input[placeholder*="search"], input[placeholder*="domain"]').first();
    await searchInput.fill('example.com');

    await page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first().click();
    await page.waitForLoadState('networkidle');

    // Should show search results or "no results" message
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should access WHOIS tool', async ({ page }) => {
    await page.goto('/whois');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // WHOIS might be disabled
    if (bodyText.toLowerCase().includes('disabled') ||
        bodyText.toLowerCase().includes('not available') ||
        bodyText.toLowerCase().includes('not found')) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await expect(page).toHaveURL(/.*whois/);
    expect(bodyText.toLowerCase()).toMatch(/whois|domain|lookup/i);
  });

  test('should show WHOIS form fields', async ({ page }) => {
    await page.goto('/whois');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Check if WHOIS is available
    if (bodyText.toLowerCase().includes('disabled') || bodyText.toLowerCase().includes('not available')) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Should have domain input field
    const domainInput = page.locator('input[name*="domain"], input[name*="host"], input[placeholder*="domain"]').first();
    if (await domainInput.count() > 0) {
      await expect(domainInput).toBeVisible();
    }
  });

  test('should validate WHOIS domain input', async ({ page }) => {
    await page.goto('/whois');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.toLowerCase().includes('disabled') || bodyText.toLowerCase().includes('not available')) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Try to submit empty form
    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    if (await submitBtn.count() > 0) {
      await submitBtn.click();
      await page.waitForLoadState('networkidle');
    }

    // Should stay on form or show validation
    const newBodyText = await page.locator('body').textContent();
    expect(newBodyText).not.toMatch(/fatal|exception/i);
  });

  test('should perform WHOIS lookup', async ({ page }) => {
    await page.goto('/whois');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.toLowerCase().includes('disabled') || bodyText.toLowerCase().includes('not available')) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Enter domain
    const domainInput = page.locator('input[name*="domain"], input[name*="host"], input[placeholder*="domain"]').first();
    if (await domainInput.count() === 0) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await domainInput.fill('example.com');

    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Should show WHOIS results or error message
    const resultText = await page.locator('body').textContent();
    expect(resultText).not.toMatch(/fatal|exception/i);
  });

  test('should access RDAP tool if enabled', async ({ page }) => {
    await page.goto('/rdap');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.toLowerCase().includes('not found') ||
        bodyText.toLowerCase().includes('disabled') ||
        bodyText.toLowerCase().includes('404')) {
      // RDAP tool not available or disabled - this is expected
      expect(bodyText).not.toMatch(/fatal|exception/i);
    } else {
      await expect(page).toHaveURL(/.*rdap/);
    }
  });

  test('should access database consistency tool if available', async ({ page }) => {
    await page.goto('/tools/database-consistency');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.toLowerCase().includes('not found') ||
        bodyText.toLowerCase().includes('permission') ||
        bodyText.toLowerCase().includes('denied') ||
        bodyText.toLowerCase().includes('404')) {
      // Tool not available or no permission - this is expected
      expect(bodyText).not.toMatch(/fatal|exception/i);
    } else {
      expect(bodyText.toLowerCase()).toMatch(/consistency|database|check/i);
    }
  });

  test('should show navigation menu items', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // Check for main navigation elements
    await expect(page.locator('nav, .navbar, .navigation, header').first()).toBeVisible();

    // Should have various menu items
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|DNS/i);
  });

  test('should have working logout functionality', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // Try direct logout URL
    await page.goto('/logout');
    await page.waitForLoadState('networkidle');

    // Should redirect to login page
    const url = page.url();
    const bodyText = await page.locator('body').textContent();
    const loggedOut = url.includes('login') ||
                      bodyText.toLowerCase().includes('login') ||
                      bodyText.toLowerCase().includes('sign in');
    expect(loggedOut).toBeTruthy();
  });
});
