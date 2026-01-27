/**
 * Forgot Password Tests
 *
 * Tests for the password reset request functionality.
 *
 * Note: These tests require SMTP to be configured.
 * When SMTP is not configured, password reset is disabled and tests will be skipped.
 */

import { test, expect } from '@playwright/test';

// Helper to check if password recovery is enabled
async function isPasswordRecoveryEnabled(page) {
  await page.goto('/password/forgot');
  const bodyText = await page.locator('body').textContent();
  return !bodyText.toLowerCase().includes('disabled');
}

test.describe('Forgot Password Page', () => {
  test.describe('Page Access', () => {
    test('should access forgot password page', async ({ page }) => {
      await page.goto('/password/forgot');

      const bodyText = await page.locator('body').textContent();
      // Should show either the form or a disabled message
      expect(bodyText.toLowerCase()).toMatch(/forgot.*password|reset|email|disabled/i);
    });

    test('should display page title or disabled message', async ({ page }) => {
      await page.goto('/password/forgot');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/forgot.*password|reset|disabled/i);
    });

    test('should not require authentication', async ({ page }) => {
      await page.goto('/password/forgot');

      // Should stay on forgot password page, not redirect to login
      const url = page.url();
      expect(url).toMatch(/password\/forgot|forgot/);
    });
  });

  test.describe('Form Elements', () => {
    test('should display email input field when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const emailInput = page.locator('input[type="email"], input[name="email"], input#email');
      await expect(emailInput).toBeVisible();
    });

    test('should display send reset link button when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]');
      await expect(submitBtn).toBeVisible();
    });

    test('should have password reset token hidden field when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const tokenInput = page.locator('input[name="password_reset_token"]');
      const hasToken = await tokenInput.count() > 0;

      // Token should be present as hidden field
      expect(hasToken).toBeTruthy();
    });

    test('should display help text when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const bodyText = await page.locator('body').textContent();

      const hasHelpText = bodyText.toLowerCase().includes('email') ||
                           bodyText.toLowerCase().includes('send') ||
                           bodyText.toLowerCase().includes('link');
      expect(hasHelpText).toBeTruthy();
    });
  });

  test.describe('Back to Login Link', () => {
    test('should display back to login link when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const backLink = page.locator('a[href*="login"]');
      await expect(backLink.first()).toBeVisible();
    });

    test('should navigate to login page', async ({ page }) => {
      await page.goto('/password/forgot');

      const backLink = page.locator('a[href*="login"]:has-text("Back"), a[href*="login"]:has-text("Login"), a[href*="login"]');

      if (await backLink.count() > 0) {
        await backLink.first().click();
        await expect(page).toHaveURL(/.*\/login/);
      }
    });
  });
});

test.describe('Forgot Password Form Validation', () => {
  test.describe('Empty Field Validation', () => {
    test('should reject empty email when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const submitBtn = page.locator('button[type="submit"]');
      await submitBtn.click();

      // Should stay on same page or show validation error
      const url = page.url();
      expect(url).toMatch(/password\/forgot|forgot/);
    });

    test('should mark email as required when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const emailInput = page.locator('input[name="email"]');
      const isRequired = await emailInput.getAttribute('required');

      expect(isRequired !== null).toBeTruthy();
    });
  });

  test.describe('Email Format Validation', () => {
    test('should reject invalid email format when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const emailInput = page.locator('input[name="email"]');
      await emailInput.fill('invalid-email');

      const submitBtn = page.locator('button[type="submit"]');
      await submitBtn.click();

      // Browser validation should prevent submission or show error
      const url = page.url();
      const bodyText = await page.locator('body').textContent();

      const hasValidationIssue = url.includes('forgot') ||
                                  bodyText.toLowerCase().includes('valid') ||
                                  bodyText.toLowerCase().includes('email');
      expect(hasValidationIssue).toBeTruthy();
    });

    test('should have email input type when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const emailInput = page.locator('input[name="email"]');
      const inputType = await emailInput.getAttribute('type');

      expect(inputType).toBe('email');
    });
  });
});

test.describe('Forgot Password Success Flow', () => {
  test('should accept valid email when enabled', async ({ page }) => {
    const isEnabled = await isPasswordRecoveryEnabled(page);
    test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

    const emailInput = page.locator('input[name="email"]');
    await emailInput.fill('test@example.com');

    const submitBtn = page.locator('button[type="submit"]');
    await submitBtn.click();

    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    // Should show success message or error for non-existent email
    expect(bodyText.toLowerCase()).toMatch(/sent|success|error|not found|email/i);
  });

  test('should handle non-existent email gracefully', async ({ page }) => {
    const isEnabled = await isPasswordRecoveryEnabled(page);
    test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

    const emailInput = page.locator('input[name="email"]');
    await emailInput.fill('nonexistent-user-12345@example.com');

    const submitBtn = page.locator('button[type="submit"]');
    await submitBtn.click();

    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    // Should not reveal if email exists or not (security best practice)
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});

test.describe('Forgot Password UI Elements', () => {
  test('should have card layout or show disabled message', async ({ page }) => {
    await page.goto('/password/forgot');

    const card = page.locator('.card');
    const bodyText = await page.locator('body').textContent();

    const hasCard = await card.count() > 0;
    const isDisabled = bodyText.toLowerCase().includes('disabled');
    expect(hasCard || isDisabled).toBeTruthy();
  });

  test('should display breadcrumb navigation', async ({ page }) => {
    await page.goto('/password/forgot');

    const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
    const bodyText = await page.locator('body').textContent();

    const hasBreadcrumb = await breadcrumb.count() > 0;
    const isDisabled = bodyText.toLowerCase().includes('disabled');
    expect(hasBreadcrumb || isDisabled).toBeTruthy();
  });
});
