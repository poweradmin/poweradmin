import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('MFA Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access MFA setup page', async ({ page }) => {
    // Try to navigate to MFA setup
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('Account')) {
      await page.getByText('Account').click();
      await page.getByText('MFA', { timeout: 5000 }).click();
    } else {
      // Direct navigation to MFA setup route
      await page.goto('/mfa/setup');
    }

    await expect(page).toHaveURL(/.*mfa/);
  });

  test('should show MFA setup form when MFA is not enabled', async ({ page }) => {
    await page.goto('/mfa/setup');

    // Should show setup form or QR code
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain('Multi-Factor Authentication');

    // Look for QR code or setup elements
    await expect(page.locator('img[src*="qr"], [class*="qr"], canvas, svg').first()).toBeVisible();
  });

  test('should display MFA setup instructions', async ({ page }) => {
    await page.goto('/mfa/setup');

    // Should contain setup instructions
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain('Google Authenticator');
    expect(bodyText).toContain('scan');
  });

  test('should handle MFA verification form', async ({ page }) => {
    await page.goto('/mfa/verify');

    // Should show verification form
    await expect(page.locator('input[name*="code"], input[placeholder*="code"]')).toBeVisible();
    await expect(page.locator('button[type="submit"], input[type="submit"]')).toBeVisible();
  });

  test('should validate MFA code format', async ({ page }) => {
    await page.goto('/mfa/verify');

    // Enter invalid code format
    await page.locator('input[name*="code"], input[placeholder*="code"]').fill('abc');
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show validation error or remain on page
    await expect(page).toHaveURL(/.*mfa\/verify/);
  });

  test('should handle empty MFA code submission', async ({ page }) => {
    await page.goto('/mfa/verify');

    // Submit without entering code
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show validation error or remain on page
    await expect(page).toHaveURL(/.*mfa\/verify/);
  });
});
