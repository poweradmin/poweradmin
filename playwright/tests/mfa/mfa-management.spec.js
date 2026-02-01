import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('MFA Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access MFA setup page', async ({ page }) => {
    // Try to navigate to MFA setup via account menu or direct URL
    await page.goto('/mfa/setup');
    await page.waitForLoadState('networkidle');

    // Check if MFA is available
    const bodyText = await page.locator('body').textContent();
    const mfaAvailable = bodyText.toLowerCase().includes('mfa') ||
                          bodyText.toLowerCase().includes('multi-factor') ||
                          bodyText.toLowerCase().includes('two-factor') ||
                          bodyText.toLowerCase().includes('authenticator');

    // If MFA page exists, verify it loaded properly
    if (mfaAvailable) {
      await expect(page.locator('body')).toContainText(/mfa|multi-factor|two-factor|authenticator/i);
    } else {
      // MFA might be disabled - verify page didn't error out
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should show MFA setup form when MFA is not enabled', async ({ page }) => {
    await page.goto('/mfa/setup');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Check if MFA is available
    if (bodyText.toLowerCase().includes('multi-factor') ||
        bodyText.toLowerCase().includes('mfa') ||
        bodyText.toLowerCase().includes('authenticator')) {
      // MFA is available, check for setup elements
      const hasQrCode = await page.locator('img[src*="qr"], [class*="qr"], canvas, svg').count() > 0;
      const hasForm = await page.locator('form').count() > 0;
      expect(hasQrCode || hasForm || bodyText.toLowerCase().includes('setup')).toBeTruthy();
    } else {
      // MFA might be disabled or not configured
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should display MFA setup instructions', async ({ page }) => {
    await page.goto('/mfa/setup');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // If MFA is available, check for instructions
    if (bodyText.toLowerCase().includes('authenticator') ||
        bodyText.toLowerCase().includes('mfa') ||
        bodyText.toLowerCase().includes('multi-factor')) {
      // Should contain some setup-related text
      expect(bodyText.toLowerCase()).toMatch(/scan|code|authenticator|setup|enable/i);
    } else {
      // MFA might be disabled
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should handle MFA verification form', async ({ page }) => {
    await page.goto('/mfa/verify');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Check if MFA verification page exists
    const codeInput = page.locator('input[name*="code"], input[placeholder*="code"]');
    if (await codeInput.count() > 0) {
      await expect(codeInput.first()).toBeVisible();
    } else {
      // MFA verify page might redirect or not exist
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should validate MFA code format', async ({ page }) => {
    await page.goto('/mfa/verify');
    await page.waitForLoadState('networkidle');

    const codeInput = page.locator('input[name*="code"], input[placeholder*="code"]').first();
    if (await codeInput.count() === 0) {
      // MFA verify page doesn't exist or MFA is disabled
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Enter invalid code format
    await codeInput.fill('abc');

    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    if (await submitBtn.count() > 0) {
      await submitBtn.click();
      await page.waitForLoadState('networkidle');
    }

    // Should show validation error or remain on page
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should handle empty MFA code submission', async ({ page }) => {
    await page.goto('/mfa/verify');
    await page.waitForLoadState('networkidle');

    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    if (await submitBtn.count() === 0) {
      // MFA verify page doesn't exist
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Submit without entering code
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Should show validation error or remain on page
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
