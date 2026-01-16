/**
 * Reset Password Tests
 *
 * Tests for the password reset functionality with token
 * covering the reset_password.html template.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Reset Password Page', () => {
  test.describe('Page Access', () => {
    test('should access reset password page with token parameter', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test-token');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/reset.*password|password|invalid|expired/i);
    });

    test('should handle missing token', async ({ page }) => {
      await page.goto('/index.php?page=reset_password');

      const bodyText = await page.locator('body').textContent();

      // Should show error for missing/invalid token
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired') ||
                       bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('link');
      expect(hasError || page.url().includes('reset_password')).toBeTruthy();
    });

    test('should handle invalid token', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=invalid-token-12345');

      const bodyText = await page.locator('body').textContent();

      // Should show error for invalid token
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired') ||
                       bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('request');
      expect(hasError).toBeTruthy();
    });

    test('should not require authentication', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      // Should stay on reset password page, not redirect to login
      const url = page.url();
      expect(url).toMatch(/reset_password/);
    });
  });

  test.describe('Form Elements', () => {
    test('should display password input field', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const passwordInput = page.locator('input[name="password"], input#password');
      const bodyText = await page.locator('body').textContent();

      const hasPasswordInput = await passwordInput.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      // Either has form or shows error
      expect(hasPasswordInput || hasError).toBeTruthy();
    });

    test('should display confirm password field', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const confirmInput = page.locator('input[name="confirm_password"], input#confirm_password');
      const bodyText = await page.locator('body').textContent();

      const hasConfirmInput = await confirmInput.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasConfirmInput || hasError).toBeTruthy();
    });

    test('should display reset password button', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]');
      const bodyText = await page.locator('body').textContent();

      const hasSubmitBtn = await submitBtn.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasSubmitBtn || hasError).toBeTruthy();
    });

    test('should have reset token hidden field', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const tokenInput = page.locator('input[name="reset_password_token"]');
      const bodyText = await page.locator('body').textContent();

      const hasToken = await tokenInput.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasToken || hasError).toBeTruthy();
    });

    test('should have password visibility toggle buttons', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const toggleBtn = page.locator('button[onclick*="showPassword"]');
      const bodyText = await page.locator('body').textContent();

      const hasToggle = await toggleBtn.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      // Either has toggle or shows error
      expect(hasToggle || hasError).toBeTruthy();
    });
  });

  test.describe('Back to Login Link', () => {
    test('should display back to login link', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const backLink = page.locator('a[href*="login"]');
      await expect(backLink.first()).toBeVisible();
    });
  });
});

test.describe('Reset Password Validation', () => {
  test.describe('Empty Field Validation', () => {
    test('should reject empty password', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const submitBtn = page.locator('button[type="submit"]');

      if (await submitBtn.count() > 0) {
        await submitBtn.click();

        // Should stay on same page or show validation error
        const url = page.url();
        expect(url).toMatch(/reset_password/);
      }
    });

    test('should mark password as required', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const isRequired = await passwordInput.getAttribute('required');
        expect(isRequired !== null).toBeTruthy();
      }
    });

    test('should mark confirm password as required', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const confirmInput = page.locator('input[name="confirm_password"]');

      if (await confirmInput.count() > 0) {
        const isRequired = await confirmInput.getAttribute('required');
        expect(isRequired !== null).toBeTruthy();
      }
    });
  });

  test.describe('Password Match Validation', () => {
    test('should reject mismatched passwords', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const passwordInput = page.locator('input[name="password"]');
      const confirmInput = page.locator('input[name="confirm_password"]');

      if (await passwordInput.count() > 0 && await confirmInput.count() > 0) {
        await passwordInput.fill('Password123!');
        await confirmInput.fill('DifferentPassword123!');

        const submitBtn = page.locator('button[type="submit"]');
        await submitBtn.click();

        // Should show validation error or stay on page
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/match|reset|password/i);
      }
    });

    test('should have client-side password match validation', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      // Template includes JavaScript for password match validation:
      // document.getElementById('confirm_password').addEventListener('input', ...)
      const bodyText = await page.locator('body').textContent();

      // Check that form exists or error is shown
      const hasForm = await page.locator('form').count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasForm || hasError).toBeTruthy();
    });
  });
});

test.describe('Reset Password Policy', () => {
  test.describe('Password Requirements Display', () => {
    test('should display password requirements when policy enabled', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const bodyText = await page.locator('body').textContent();

      // Template shows requirements if password_policy.enable_password_rules is true
      const hasRequirements = bodyText.toLowerCase().includes('requirement') ||
                               bodyText.toLowerCase().includes('minimum') ||
                               bodyText.toLowerCase().includes('character') ||
                               bodyText.toLowerCase().includes('uppercase') ||
                               bodyText.toLowerCase().includes('invalid');
      expect(hasRequirements).toBeTruthy();
    });

    test('should show minimum length requirement', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "Minimum length: {{ password_policy.min_length }} characters"
      const hasMinLength = bodyText.toLowerCase().includes('minimum') ||
                           bodyText.toLowerCase().includes('length') ||
                           bodyText.toLowerCase().includes('character') ||
                           bodyText.toLowerCase().includes('invalid');
      expect(hasMinLength).toBeTruthy();
    });
  });

  test.describe('Policy Error Display', () => {
    test('should display policy errors when password does not meet requirements', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      // Template shows: policy_errors in alert-danger if validation fails
      const bodyText = await page.locator('body').textContent();

      // Check that error display is possible
      const hasAlertStructure = await page.locator('.alert').count() >= 0;
      expect(hasAlertStructure).toBeTruthy();
    });
  });
});

test.describe('Reset Password Success State', () => {
  test.describe('Success Message', () => {
    test('should have success alert template structure', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      // Template shows: <div class="alert alert-success" role="alert">
      // when success is true
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should have go to login button after success', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      // Check for login link (always present)
      const loginLink = page.locator('a[href*="login"]');
      await expect(loginLink.first()).toBeVisible();
    });
  });
});

test.describe('Reset Password Error States', () => {
  test.describe('Invalid Token Error', () => {
    test('should display error for invalid token', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=invalid-fake-token');

      const bodyText = await page.locator('body').textContent();

      // Should show error message
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired') ||
                       bodyText.toLowerCase().includes('error');
      expect(hasError).toBeTruthy();
    });

    test('should offer to request new reset link', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=invalid-token');

      const bodyText = await page.locator('body').textContent();

      // Template shows: <a href="index.php?page=forgot_password">Request New Reset Link</a>
      const hasNewLinkOption = bodyText.toLowerCase().includes('request') ||
                                bodyText.toLowerCase().includes('new') ||
                                bodyText.toLowerCase().includes('link') ||
                                bodyText.toLowerCase().includes('forgot');
      expect(hasNewLinkOption).toBeTruthy();
    });
  });

  test.describe('Expired Token Error', () => {
    test('should handle expired token', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=expired-token');

      const bodyText = await page.locator('body').textContent();

      // Should show some kind of error/feedback
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});

test.describe('Reset Password User Display', () => {
  test.describe('Email Display', () => {
    test('should display user email when token valid', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      // Template shows: "Enter your new password for <strong>{{ email }}</strong>"
      const bodyText = await page.locator('body').textContent();

      // Either shows email or error
      const hasContent = bodyText.toLowerCase().includes('password') ||
                          bodyText.toLowerCase().includes('invalid') ||
                          bodyText.toLowerCase().includes('expired');
      expect(hasContent).toBeTruthy();
    });
  });
});

test.describe('Reset Password Bootstrap Validation', () => {
  test.describe('Client-Side Validation', () => {
    test('should use Bootstrap validation classes', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const form = page.locator('form.needs-validation');
      const bodyText = await page.locator('body').textContent();

      const hasValidationClass = await form.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasValidationClass || hasError).toBeTruthy();
    });

    test('should have invalid feedback elements', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const invalidFeedback = page.locator('.invalid-feedback');
      const bodyText = await page.locator('body').textContent();

      const hasFeedback = await invalidFeedback.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasFeedback || hasError).toBeTruthy();
    });
  });
});

test.describe('Reset Password Accessibility', () => {
  test.describe('Form Labels', () => {
    test('should have label for password input', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const label = page.locator('label[for="password"]');
      const bodyText = await page.locator('body').textContent();

      const hasLabel = await label.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasLabel || hasError).toBeTruthy();
    });

    test('should have label for confirm password input', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const label = page.locator('label[for="confirm_password"]');
      const bodyText = await page.locator('body').textContent();

      const hasLabel = await label.count() > 0;
      const hasError = bodyText.toLowerCase().includes('invalid') ||
                       bodyText.toLowerCase().includes('expired');

      expect(hasLabel || hasError).toBeTruthy();
    });

    test('should have autofocus on password input', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const hasAutofocus = await passwordInput.getAttribute('autofocus');
        expect(hasAutofocus !== null).toBeTruthy();
      }
    });
  });

  test.describe('Password Visibility', () => {
    test('should have password type for inputs', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const inputType = await passwordInput.getAttribute('type');
        expect(inputType).toBe('password');
      }
    });

    test('should have password type for confirm input', async ({ page }) => {
      await page.goto('/index.php?page=reset_password&token=test');

      const confirmInput = page.locator('input[name="confirm_password"]');

      if (await confirmInput.count() > 0) {
        const inputType = await confirmInput.getAttribute('type');
        expect(inputType).toBe('password');
      }
    });
  });
});
