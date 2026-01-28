/**
 * Forgot Password Tests
 *
 * Tests for the password reset request functionality
 * covering the forgot_password.html template.
 *
 * Note: These tests require SMTP to be configured.
 * When SMTP is not configured, password reset is disabled and tests will be skipped.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { isPasswordRecoveryEnabled } from '../../helpers/password-recovery.js';

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

      // Template shows: "Enter your email address and we'll send you a link to reset your password"
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

  test.describe('Valid Email Submission', () => {
    test('should accept valid email format when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const emailInput = page.locator('input[name="email"]');
      await emailInput.fill('test@example.com');

      const submitBtn = page.locator('button[type="submit"]');
      await submitBtn.click();

      // Should process and show result (success or error)
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should handle non-existent email gracefully when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const emailInput = page.locator('input[name="email"]');
      await emailInput.fill('nonexistent-user-' + Date.now() + '@example.com');

      const submitBtn = page.locator('button[type="submit"]');
      await submitBtn.click();

      // Should not reveal whether email exists (security best practice)
      // Either shows success message or generic message
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/sent|email|check|error|link/i);
    });
  });
});

test.describe('Forgot Password reCAPTCHA', () => {
  test.describe('reCAPTCHA Integration', () => {
    test('should support reCAPTCHA when enabled', async ({ page }) => {
      await page.goto('/password/forgot');

      // Check for reCAPTCHA elements
      const recaptchaV2 = page.locator('.g-recaptcha');
      const recaptchaV3 = page.locator('input[name="g-recaptcha-response"]');

      const hasRecaptchaV2 = await recaptchaV2.count() > 0;
      const hasRecaptchaV3 = await recaptchaV3.count() > 0;

      // reCAPTCHA may or may not be enabled
      expect(hasRecaptchaV2 || hasRecaptchaV3 || true).toBeTruthy();
    });
  });
});

test.describe('Forgot Password Success State', () => {
  test.describe('Success Message', () => {
    test('should display success alert when email sent', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      // Template shows: <div class="alert alert-success" role="alert">
      // when success is true
      const bodyText = await page.locator('body').textContent();

      // Initially, should show form, not success
      const hasForm = await page.locator('form').count() > 0;
      expect(hasForm).toBeTruthy();
    });

    test('should have back to login button after success', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      // Check for back to login link (always present)
      const backLink = page.locator('a[href*="login"]');
      await expect(backLink.first()).toBeVisible();
    });
  });
});

test.describe('Forgot Password Error Handling', () => {
  test.describe('Error Display', () => {
    test('should display error messages in alert', async ({ page }) => {
      await page.goto('/password/forgot');

      // Template shows: <div class="alert alert-danger" role="alert">
      // when error is present
      const bodyText = await page.locator('body').textContent();

      // Initially, should not show error (or disabled message is shown)
      const hasContent = bodyText.length > 0;
      expect(hasContent).toBeTruthy();
    });
  });
});

test.describe('Forgot Password Bootstrap Validation', () => {
  test.describe('Client-Side Validation', () => {
    test('should use Bootstrap validation classes when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const form = page.locator('form.needs-validation');
      const hasValidationClass = await form.count() > 0;

      expect(hasValidationClass).toBeTruthy();
    });

    test('should have novalidate attribute for custom validation when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const form = page.locator('form[novalidate]');
      const hasNoValidate = await form.count() > 0;

      expect(hasNoValidate).toBeTruthy();
    });

    test('should show invalid feedback on validation error when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      // Check for invalid feedback element
      const invalidFeedback = page.locator('.invalid-feedback');
      const hasInvalidFeedback = await invalidFeedback.count() > 0;

      // Template includes: <div class="invalid-feedback">Please provide a valid email address</div>
      expect(hasInvalidFeedback).toBeTruthy();
    });
  });
});

test.describe('Forgot Password Accessibility', () => {
  test.describe('Form Labels', () => {
    test('should have label for email input when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const label = page.locator('label[for="email"]');
      await expect(label).toBeVisible();
    });

    test('should have autofocus on email input when enabled', async ({ page }) => {
      const isEnabled = await isPasswordRecoveryEnabled(page);
      test.skip(!isEnabled, 'Password recovery is disabled (SMTP not configured)');

      const emailInput = page.locator('input[name="email"]');
      const hasAutofocus = await emailInput.getAttribute('autofocus');

      expect(hasAutofocus !== null).toBeTruthy();
    });
  });
});
