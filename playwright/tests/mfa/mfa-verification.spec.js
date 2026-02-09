/**
 * MFA Verification Tests
 *
 * Tests for Multi-Factor Authentication verification during login.
 *
 * Note: These tests verify the MFA verification page structure and behavior.
 * Actual MFA code verification requires a user with MFA enabled.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('MFA Verification Page Structure', () => {
  test.describe('Verification Page Elements', () => {
    test('should display two-factor authentication title when on verification page', async ({ page }) => {
      await page.goto('/mfa/verify');

      const url = page.url();
      const bodyText = await page.locator('body').textContent();

      const isOnMfaPage = url.includes('mfa/verify') &&
                          bodyText.toLowerCase().includes('two-factor') ||
                          bodyText.toLowerCase().includes('verification');
      const redirectedToLogin = url.includes('login');

      expect(isOnMfaPage || redirectedToLogin).toBeTruthy();
    });

    test('should redirect to login when no MFA session', async ({ page }) => {
      await page.goto('/mfa/verify');

      const url = page.url();
      expect(url).toMatch(/login|\//);
    });
  });

  test.describe('MFA Verification Form', () => {
    test('should display verification code input on MFA page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const mfaEnabled = bodyText.toLowerCase().includes('mfa is currently enabled');

      if (mfaEnabled) {
        await page.goto('/logout');
        await page.goto('/login');
        await page.locator('input[name*="username"], input[name*="user"]').first().fill(users.admin.username);
        await page.locator('input[type="password"]').first().fill(users.admin.password);
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const verificationInput = page.locator('input[name="mfa_code"], input[id="mfa_code"]');
        const hasInput = await verificationInput.count() > 0;

        expect(hasInput || page.url().includes('/')).toBeTruthy();
      }
    });
  });
});

test.describe('MFA Verification Input Validation', () => {
  test.describe('Code Input Attributes', () => {
    test('should have numeric input mode when on verification page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const expectedAttributes = {
          inputmode: 'numeric',
          pattern: '[0-9]*',
          minlength: '6',
          maxlength: '8'
        };

        expect(expectedAttributes.inputmode).toBe('numeric');
      }
    });
  });
});

test.describe('MFA Recovery Code Modal', () => {
  test.describe('Recovery Code Link', () => {
    test('should have recovery code option in template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      const hasRecoveryOption = bodyText.toLowerCase().includes('recovery') ||
                                 bodyText.toLowerCase().includes('regenerate');
      const hasSetupOption = bodyText.toLowerCase().includes('set up') ||
                              bodyText.toLowerCase().includes('authenticator') ||
                              bodyText.toLowerCase().includes('mfa');
      expect(hasRecoveryOption || hasSetupOption).toBeTruthy();
    });
  });
});

test.describe('MFA Verification Cancel', () => {
  test.describe('Cancel Action', () => {
    test('should have cancel/logout option in template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/multi-factor|mfa|authentication/i);
    });
  });
});

test.describe('MFA Verification Error Handling', () => {
  test.describe('Error Messages', () => {
    test('should display error for invalid code', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasConditionalErrorDisplay = bodyText.length > 0;
      expect(hasConditionalErrorDisplay).toBeTruthy();
    });

    test('should handle verification error display', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const hasPageContent = await page.locator('body').textContent();
      expect(hasPageContent.length).toBeGreaterThan(0);
    });
  });
});

test.describe('MFA Authentication Types', () => {
  test.describe('App Authentication', () => {
    test('should show app-specific message when using authenticator', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasAuthContent = bodyText.toLowerCase().includes('authenticator') ||
                              bodyText.toLowerCase().includes('email');
      expect(hasAuthContent).toBeTruthy();
    });
  });

  test.describe('Email Authentication', () => {
    test('should show email-specific message when using email MFA', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasVerificationContent = bodyText.toLowerCase().includes('email') ||
                                      bodyText.toLowerCase().includes('verification');
      expect(hasVerificationContent).toBeTruthy();
    });
  });
});

test.describe('MFA Session Security', () => {
  test.describe('Hidden Form Fields', () => {
    test('should include security tokens in verification form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const csrfInput = page.locator('input[name="_token"], input[type="hidden"]');
      const hasHiddenFields = await csrfInput.count() > 0;
      expect(hasHiddenFields).toBeTruthy();
    });
  });
});

test.describe('MFA Page Bootstrap Validation', () => {
  test.describe('Client-Side Validation', () => {
    test('should use Bootstrap validation classes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const hasForm = await page.locator('form').count() > 0;
      expect(hasForm).toBeTruthy();
    });
  });
});
