/**
 * Branding Tests
 *
 * Tests for favicon link and header logo rendered in the page head/header.
 * Both fall back to bundled assets when favicon_path/logo_path are not configured.
 */

import { test, expect } from '@playwright/test';

test.describe('Branding', () => {
  test('should declare favicon in page head', async ({ page }) => {
    await page.goto('/login');

    const favicon = page.locator('head link[rel="icon"]');
    await expect(favicon).toHaveCount(1);

    const href = await favicon.getAttribute('href');
    expect(href).toMatch(/favicon\.ico$/);
  });

  test('should display header logo', async ({ page }) => {
    await page.goto('/login');

    const logo = page.locator('header img').first();
    await expect(logo).toBeVisible();

    const src = await logo.getAttribute('src');
    expect(src).toMatch(/\/assets\/logo\.png$/);
  });
});
