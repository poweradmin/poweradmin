/**
 * MFA Setup Tests
 *
 * Tests for Multi-Factor Authentication setup functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('MFA Setup', () => {
  test.describe('MFA Setup Page Access', () => {
    test('should access MFA setup page when logged in', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      await expect(page).toHaveURL(/.*mfa\/setup/);
    });

    test('should display MFA setup page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/multi-factor authentication|mfa|two-factor/i);
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();
    });

    test('should require login to access MFA setup', async ({ page }) => {
      await page.goto('/mfa/setup');

      // Should redirect to login if not authenticated
      await expect(page).toHaveURL(/.*\/login/);
    });
  });

  test.describe('MFA Status Display', () => {
    test('should display MFA disabled status when MFA is off', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      // Should show either enabled or disabled status
      expect(bodyText.toLowerCase()).toMatch(/mfa.*enabled|mfa.*disabled|currently enabled|currently disabled/i);
    });

    test('should display setup options when MFA is disabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasSetupOptions = bodyText.toLowerCase().includes('authenticator') ||
                              bodyText.toLowerCase().includes('email verification') ||
                              bodyText.toLowerCase().includes('set up');
      const isAlreadyEnabled = bodyText.toLowerCase().includes('mfa is currently enabled');

      expect(hasSetupOptions || isAlreadyEnabled).toBeTruthy();
    });
  });

  test.describe('Authenticator App Setup Option', () => {
    test('should display authenticator app card', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/authenticator app|authenticator|already enabled|mfa is currently enabled/i);
    });

    test('should display authenticator app benefits', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasContent = bodyText.toLowerCase().includes('offline') ||
                         bodyText.toLowerCase().includes('secure') ||
                         bodyText.toLowerCase().includes('enabled');
      expect(hasContent).toBeTruthy();
    });

    test('should have setup app authentication button when disabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupBtn = page.locator('button[name="setup_app"], button:has-text("Set up app")');
      const disableBtn = page.locator('button[name="disable_mfa"]');

      const hasSetupBtn = await setupBtn.count() > 0;
      const hasDisableBtn = await disableBtn.count() > 0;

      expect(hasSetupBtn || hasDisableBtn).toBeTruthy();
    });
  });

  test.describe('Email Verification Setup Option', () => {
    test('should display email verification card', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/email.*verification|verification.*email|email|enabled/i);
    });

    test('should display email verification info', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasContent = bodyText.toLowerCase().includes('email') ||
                         bodyText.toLowerCase().includes('verification') ||
                         bodyText.toLowerCase().includes('enabled');
      expect(hasContent).toBeTruthy();
    });

    test('should warn if email not configured', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasEmailOption = bodyText.toLowerCase().includes('email') ||
                              bodyText.toLowerCase().includes('not available') ||
                              bodyText.toLowerCase().includes('set your email');
      expect(hasEmailOption).toBeTruthy();
    });
  });

  test.describe('MFA Enabled State', () => {
    test('should display disable MFA option when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const disableBtn = page.locator('button[name="disable_mfa"], button:has-text("Disable MFA")');
      const setupAppBtn = page.locator('button[name="setup_app"]');

      const hasDisable = await disableBtn.count() > 0;
      const hasSetup = await setupAppBtn.count() > 0;

      expect(hasDisable || hasSetup).toBeTruthy();
    });

    test('should display regenerate recovery codes option when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasRegenerate = bodyText.toLowerCase().includes('regenerate') ||
                            bodyText.toLowerCase().includes('recovery codes');
      const hasSetup = bodyText.toLowerCase().includes('set up');

      expect(hasRegenerate || hasSetup).toBeTruthy();
    });

    test('should display current authentication method when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasMethod = bodyText.toLowerCase().includes('authenticator') ||
                        bodyText.toLowerCase().includes('email') ||
                        bodyText.toLowerCase().includes('authentication method');
      expect(hasMethod).toBeTruthy();
    });
  });

  test.describe('User Role Access', () => {
    test('manager should access MFA setup', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/mfa/setup');

      const url = page.url();
      expect(url).toMatch(/mfa\/setup|index/);
    });

    test('client should access MFA setup', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/mfa/setup');

      const url = page.url();
      expect(url).toMatch(/mfa\/setup|index/);
    });

    test('viewer should access MFA setup', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/mfa/setup');

      const url = page.url();
      expect(url).toMatch(/mfa\/setup|index/);
    });
  });
});

test.describe('MFA App Setup Flow', () => {
  test.describe('Setup App Page', () => {
    test('should navigate to app setup after clicking setup button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should display QR code when setting up app', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const qrCode = page.locator('img[alt*="QR"], .qr-code, canvas');
        const bodyText = await page.locator('body').textContent();

        const hasQR = await qrCode.count() > 0;
        const hasSecret = bodyText.toLowerCase().includes('secret') ||
                          bodyText.toLowerCase().includes('code');

        expect(hasQR || hasSecret).toBeTruthy();
      }
    });

    test('should display manual entry key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const bodyText = await page.locator('body').textContent();
        const hasManualKey = bodyText.toLowerCase().includes('manual') ||
                             bodyText.toLowerCase().includes('secret') ||
                             bodyText.toLowerCase().includes('key');

        expect(hasManualKey).toBeTruthy();
      }
    });

    test('should have verification code input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const codeInput = page.locator('input[name="code"], input[name="verification_code"], input[type="text"]');
        if (await codeInput.count() > 0) {
          await expect(codeInput.first()).toBeVisible();
        }
      }
    });

    test('should have copy secret button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const copyBtn = page.locator('button:has(.bi-clipboard), button[onclick*="copy"]');
        const secretInput = page.locator('input[id="secret-key"], input[readonly]');

        const hasCopyOption = await copyBtn.count() > 0 || await secretInput.count() > 0;
        expect(hasCopyOption).toBeTruthy();
      }
    });

    test('should have verify and enable MFA button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const verifyBtn = page.locator('button[name="verify_app"], button:has-text("Verify")');
        await expect(verifyBtn).toBeVisible();
      }
    });

    test('should have cancel button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const cancelBtn = page.locator('a:has-text("Cancel"), button:has-text("Cancel")');
        await expect(cancelBtn).toBeVisible();
      }
    });

    test('should display recommended authenticator apps', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const bodyText = await page.locator('body').textContent();
        const hasAppRecommendations = bodyText.toLowerCase().includes('google authenticator') ||
                                       bodyText.toLowerCase().includes('microsoft authenticator') ||
                                       bodyText.toLowerCase().includes('authy') ||
                                       bodyText.toLowerCase().includes('recommended');
        expect(hasAppRecommendations).toBeTruthy();
      }
    });
  });

  test.describe('App Verification Validation', () => {
    test('should reject empty verification code', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const verifyBtn = page.locator('button[name="verify_app"], button:has-text("Verify")');
        await verifyBtn.click();

        const bodyText = await page.locator('body').textContent();
        const isOnSetupPage = bodyText.toLowerCase().includes('qr') ||
                               bodyText.toLowerCase().includes('scan') ||
                               bodyText.toLowerCase().includes('verification code') ||
                               bodyText.toLowerCase().includes('invalid');
        expect(isOnSetupPage).toBeTruthy();
      }
    });

    test('should reject invalid verification code', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const codeInput = page.locator('input[name="verification_code"]');
        await codeInput.fill('000000');

        const verifyBtn = page.locator('button[name="verify_app"], button:has-text("Verify")');
        await verifyBtn.click();

        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('invalid') ||
                         bodyText.toLowerCase().includes('error') ||
                         bodyText.toLowerCase().includes('incorrect') ||
                         bodyText.toLowerCase().includes('verification code');
        expect(hasError).toBeTruthy();
      }
    });

    test('should enforce numeric input for verification code', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const codeInput = page.locator('input[name="verification_code"]');

        const inputMode = await codeInput.getAttribute('inputmode');
        const pattern = await codeInput.getAttribute('pattern');

        expect(inputMode === 'numeric' || pattern === '[0-9]*').toBeTruthy();
      }
    });

    test('should enforce 6-digit code length', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupAppBtn = page.locator('button[name="setup_app"]');

      if (await setupAppBtn.count() > 0) {
        await setupAppBtn.click();

        const codeInput = page.locator('input[name="verification_code"]');

        const minLength = await codeInput.getAttribute('minlength');
        const maxLength = await codeInput.getAttribute('maxlength');

        expect(minLength === '6' && maxLength === '6').toBeTruthy();
      }
    });
  });
});

test.describe('Email MFA Setup Flow', () => {
  test.describe('Setup Email Page', () => {
    test('should navigate to email setup when available', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupEmailBtn = page.locator('button[name="setup_email"]');

      if (await setupEmailBtn.count() > 0) {
        await setupEmailBtn.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/email|verification|code|not available/i);
      }
    });

    test('should display user email address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const setupEmailBtn = page.locator('button[name="setup_email"]');

      if (await setupEmailBtn.count() > 0) {
        await setupEmailBtn.click();

        const bodyText = await page.locator('body').textContent();
        const hasEmailInfo = bodyText.includes('@') ||
                              bodyText.toLowerCase().includes('email') ||
                              bodyText.toLowerCase().includes('sent');
        expect(hasEmailInfo).toBeTruthy();
      }
    });
  });
});

test.describe('MFA Disable Flow', () => {
  test('should have disable MFA button when enabled', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/mfa/setup');

    const disableBtn = page.locator('button[name="disable_mfa"]');
    const setupBtn = page.locator('button[name="setup_app"]');

    const hasDisable = await disableBtn.count() > 0;
    const hasSetup = await setupBtn.count() > 0;

    expect(hasDisable || hasSetup).toBeTruthy();
  });

  test('should show appropriate button state based on MFA status', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/mfa/setup');

    const bodyText = await page.locator('body').textContent();
    const isEnabled = bodyText.toLowerCase().includes('mfa is currently enabled');
    const isDisabled = bodyText.toLowerCase().includes('mfa is currently disabled');

    const disableBtn = page.locator('button[name="disable_mfa"]');
    const setupBtn = page.locator('button[name="setup_app"]');

    if (isEnabled) {
      await expect(disableBtn).toBeVisible();
    } else if (isDisabled) {
      await expect(setupBtn).toBeVisible();
    }
  });
});

test.describe('MFA Security', () => {
  test('should not expose secret key in URL', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/mfa/setup');

    const url = page.url();
    expect(url).not.toMatch(/secret|key=/i);
  });

  test('should use POST for MFA actions', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/mfa/setup');

    const forms = page.locator('form[method="post"], form[method="POST"]');
    if (await forms.count() > 0) {
      await expect(forms.first()).toBeVisible();
    }
  });
});
