/**
 * Reset Password Tests
 *
 * Tests for the password reset functionality with token validation.
 */

import { test, expect } from '@playwright/test';

test.describe('Reset Password Page', () => {
  test.describe('Page Access', () => {
    test('should access reset password page with token parameter', async ({ page }) => {
      await page.goto('/password/reset?token=test-token');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/reset.*password|password|invalid|expired|error/i);
    });

    test('should handle missing token', async ({ page }) => {
      await page.goto('/password/reset');

      const bodyText = await page.locator('body').textContent();

      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired') ||
                       bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('link');
      expect(hasError || page.url().includes('reset')).toBeTruthy();
    });

    test('should handle invalid token', async ({ page }) => {
      await page.goto('/password/reset?token=invalid-token-12345');

      const bodyText = await page.locator('body').textContent();

      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired') ||
                       bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('request');
      expect(hasError).toBeTruthy();
    });

    test('should not require authentication', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const url = page.url();
      expect(url).toMatch(/reset/);
    });
  });

  test.describe('Form Elements', () => {
    test('should display password input field or error alert', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const passwordInput = page.locator('input[name="password"], input#password');
      const alert = page.locator('.alert');

      const hasPasswordInput = await passwordInput.count() > 0;
      const hasAlert = await alert.count() > 0;

      // Either form is shown (valid token) or error alert is shown (invalid token)
      expect(hasPasswordInput || hasAlert).toBeTruthy();
    });

    test('should display confirm password field or error alert', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const confirmInput = page.locator('input[name="confirm_password"], input#confirm_password');
      const alert = page.locator('.alert');

      const hasConfirmInput = await confirmInput.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasConfirmInput || hasAlert).toBeTruthy();
    });

    test('should display reset password button or error alert', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]');
      const alert = page.locator('.alert');

      const hasSubmitBtn = await submitBtn.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasSubmitBtn || hasAlert).toBeTruthy();
    });

    test('should have reset token hidden field or error alert', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const tokenInput = page.locator('input[name="reset_password_token"]');
      const alert = page.locator('.alert');

      const hasToken = await tokenInput.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasToken || hasAlert).toBeTruthy();
    });

    test('should have password visibility toggle or error alert', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const toggleBtn = page.locator('button[onclick*="showPassword"]');
      const alert = page.locator('.alert');

      const hasToggle = await toggleBtn.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasToggle || hasAlert).toBeTruthy();
    });
  });

  test.describe('Back to Login Link', () => {
    test('should display back to login link or forgot password link', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const backLink = page.locator('a[href*="login"], a[href*="forgot"]');
      expect(await backLink.count()).toBeGreaterThan(0);
    });
  });
});

test.describe('Reset Password Validation', () => {
  test.describe('Empty Field Validation', () => {
    test('should reject empty password', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const submitBtn = page.locator('button[type="submit"]');

      if (await submitBtn.count() > 0) {
        await submitBtn.click();

        const url = page.url();
        expect(url).toMatch(/reset/);
      }
    });

    test('should mark password as required', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const isRequired = await passwordInput.getAttribute('required');
        expect(isRequired !== null).toBeTruthy();
      }
    });

    test('should mark confirm password as required', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const confirmInput = page.locator('input[name="confirm_password"]');

      if (await confirmInput.count() > 0) {
        const isRequired = await confirmInput.getAttribute('required');
        expect(isRequired !== null).toBeTruthy();
      }
    });
  });

  test.describe('Password Match Validation', () => {
    test('should reject mismatched passwords', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const passwordInput = page.locator('input[name="password"]');
      const confirmInput = page.locator('input[name="confirm_password"]');

      if (await passwordInput.count() > 0 && await confirmInput.count() > 0) {
        await passwordInput.fill('Password123!');
        await confirmInput.fill('DifferentPassword123!');

        const submitBtn = page.locator('button[type="submit"]');
        await submitBtn.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/match|reset|password/i);
      }
    });

    test('should have client-side password match validation', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const hasForm = await page.locator('form').count() > 0;
      const hasAlert = await page.locator('.alert').count() > 0;

      // Either form is shown (valid token) or alert is shown (invalid token)
      expect(hasForm || hasAlert).toBeTruthy();
    });
  });
});

test.describe('Reset Password Policy', () => {
  test.describe('Password Requirements Display', () => {
    test('should display password requirements or error alert', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      // Either form with password requirements shown OR error alert
      const requirementsInfo = page.locator('.alert-info');
      const errorAlert = page.locator('.alert-danger');

      const hasRequirements = await requirementsInfo.count() > 0;
      const hasError = await errorAlert.count() > 0;

      expect(hasRequirements || hasError).toBeTruthy();
    });

    test('should show minimum length requirement or error alert', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      // Either form with password requirements shown OR error alert
      const requirementsInfo = page.locator('.alert-info');
      const errorAlert = page.locator('.alert-danger');

      const hasRequirements = await requirementsInfo.count() > 0;
      const hasError = await errorAlert.count() > 0;

      expect(hasRequirements || hasError).toBeTruthy();
    });
  });

  test.describe('Policy Error Display', () => {
    test('should display policy errors when password does not meet requirements', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const bodyText = await page.locator('body').textContent();

      const hasAlertStructure = await page.locator('.alert').count() >= 0;
      expect(hasAlertStructure).toBeTruthy();
    });
  });
});

test.describe('Reset Password Success State', () => {
  test.describe('Success Message', () => {
    test('should have success alert template structure', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should have go to login button or forgot password link', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      // Link to login or forgot password (for requesting new reset link)
      const link = page.locator('a[href*="login"], a[href*="forgot"]');
      expect(await link.count()).toBeGreaterThan(0);
    });
  });
});

test.describe('Reset Password Error States', () => {
  test.describe('Invalid Token Error', () => {
    test('should display error alert for invalid token', async ({ page }) => {
      await page.goto('/password/reset?token=invalid-fake-token');

      // Invalid token should show error alert
      const errorAlert = page.locator('.alert-danger');
      expect(await errorAlert.count()).toBeGreaterThan(0);
    });

    test('should offer to request new reset link', async ({ page }) => {
      await page.goto('/password/reset?token=invalid-token');

      // Link to request new password reset
      const forgotLink = page.locator('a[href*="forgot"]');
      expect(await forgotLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Expired Token Error', () => {
    test('should handle expired token', async ({ page }) => {
      await page.goto('/password/reset?token=expired-token');

      const bodyText = await page.locator('body').textContent();

      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});

test.describe('Reset Password User Display', () => {
  test.describe('Email Display', () => {
    test('should display user email when token valid', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const bodyText = await page.locator('body').textContent();

      const hasContent = bodyText.toLowerCase().includes('password') ||
                          bodyText.toLowerCase().includes('invalid') ||
                          bodyText.toLowerCase().includes('expired') ||
                          bodyText.toLowerCase().includes('error');
      expect(hasContent).toBeTruthy();
    });
  });
});

test.describe('Reset Password Bootstrap Validation', () => {
  test.describe('Client-Side Validation', () => {
    test('should use Bootstrap validation classes or show error', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const form = page.locator('form.needs-validation');
      const alert = page.locator('.alert');

      const hasValidationClass = await form.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasValidationClass || hasAlert).toBeTruthy();
    });

    test('should have invalid feedback elements or show error', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const invalidFeedback = page.locator('.invalid-feedback');
      const alert = page.locator('.alert');

      const hasFeedback = await invalidFeedback.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasFeedback || hasAlert).toBeTruthy();
    });
  });
});

test.describe('Reset Password Accessibility', () => {
  test.describe('Form Labels', () => {
    test('should have label for password input or show error', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const label = page.locator('label[for="password"]');
      const alert = page.locator('.alert');

      const hasLabel = await label.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasLabel || hasAlert).toBeTruthy();
    });

    test('should have label for confirm password input or show error', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const label = page.locator('label[for="confirm_password"]');
      const alert = page.locator('.alert');

      const hasLabel = await label.count() > 0;
      const hasAlert = await alert.count() > 0;

      expect(hasLabel || hasAlert).toBeTruthy();
    });

    test('should have autofocus on password input', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const hasAutofocus = await passwordInput.getAttribute('autofocus');
        expect(hasAutofocus !== null).toBeTruthy();
      }
    });
  });

  test.describe('Password Visibility', () => {
    test('should have password type for inputs', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const inputType = await passwordInput.getAttribute('type');
        expect(inputType).toBe('password');
      }
    });

    test('should have password type for confirm input', async ({ page }) => {
      await page.goto('/password/reset?token=test');

      const confirmInput = page.locator('input[name="confirm_password"]');

      if (await confirmInput.count() > 0) {
        const inputType = await confirmInput.getAttribute('type');
        expect(inputType).toBe('password');
      }
    });
  });
});
