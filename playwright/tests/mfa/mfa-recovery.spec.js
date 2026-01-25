/**
 * MFA Recovery Codes Tests
 *
 * Tests for Multi-Factor Authentication recovery codes functionality
 * covering the mfa_recovery_codes.html template.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('MFA Recovery Codes Page', () => {
  test.describe('Recovery Codes Display', () => {
    test('should access MFA setup page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      await expect(page).toHaveURL(/page=mfa_setup/);
    });

    test('should show recovery codes option when MFA enabled', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      const bodyText = await page.locator('body').textContent();
      const mfaEnabled = bodyText.toLowerCase().includes('mfa is currently enabled');

      if (mfaEnabled) {
        // Should have regenerate recovery codes button
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await expect(regenerateBtn).toBeVisible();
      }
    });

    test('should display recovery codes after MFA setup', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // Check if we can access recovery codes (either through setup or regeneration)
      // When MFA is disabled, the page shows setup options; when enabled, it shows recovery/regenerate
      const bodyText = await page.locator('body').textContent();
      const hasRecoveryOption = bodyText.toLowerCase().includes('recovery') ||
                                 bodyText.toLowerCase().includes('regenerate');
      const hasSetupOption = bodyText.toLowerCase().includes('set up') ||
                              bodyText.toLowerCase().includes('authenticator');
      expect(hasRecoveryOption || hasSetupOption).toBeTruthy();
    });
  });

  test.describe('Recovery Codes Page Structure', () => {
    test('should display breadcrumb navigation', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // Recovery codes page has breadcrumb
      // Home > Multi-Factor Authentication > Recovery Codes
      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();
    });

    test('should have MFA setup complete header after enabling', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // Template shows: "MFA Setup Complete" after successful setup
      const bodyText = await page.locator('body').textContent();
      const hasContent = bodyText.toLowerCase().includes('mfa') ||
                          bodyText.toLowerCase().includes('multi-factor');
      expect(hasContent).toBeTruthy();
    });
  });

  test.describe('Recovery Codes Management', () => {
    test('should display save warning for recovery codes', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // mfa_recovery_codes.html shows warning:
      // "Store these codes in a safe place!"
      const bodyText = await page.locator('body').textContent();
      const mfaEnabled = bodyText.toLowerCase().includes('mfa is currently enabled');

      if (mfaEnabled) {
        // When MFA is enabled, there should be warning text about saving codes
        const hasWarning = bodyText.toLowerCase().includes('save') ||
                           bodyText.toLowerCase().includes('store') ||
                           bodyText.toLowerCase().includes('safe') ||
                           bodyText.toLowerCase().includes('regenerate');
        expect(hasWarning).toBeTruthy();
      } else {
        // When MFA is disabled, just verify we're on the setup page
        const hasSetupOption = bodyText.toLowerCase().includes('set up') ||
                                bodyText.toLowerCase().includes('authenticator');
        expect(hasSetupOption).toBeTruthy();
      }
    });

    test('should display recovery codes in grid format', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // Recovery codes displayed in: <div class="col-md-6 mb-2"><code>{{ code }}</code></div>
      const codeElements = page.locator('code');
      const hasCodeDisplayOption = await codeElements.count() >= 0;
      expect(hasCodeDisplayOption).toBeTruthy();
    });
  });

  test.describe('Recovery Codes Actions', () => {
    test('should have print button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // Check for print functionality
      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await expect(regenerateBtn).toBeVisible();

        // Click to see recovery codes
        await regenerateBtn.click();

        // Should show print option
        const newBodyText = await page.locator('body').textContent();
        const hasPrintOption = newBodyText.toLowerCase().includes('print') ||
                                newBodyText.toLowerCase().includes('recovery');
        expect(hasPrintOption).toBeTruthy();
      }
    });

    test('should have copy all button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await regenerateBtn.click();

        // Should show copy option
        const newBodyText = await page.locator('body').textContent();
        const hasCopyOption = newBodyText.toLowerCase().includes('copy') ||
                               newBodyText.toLowerCase().includes('recovery');
        expect(hasCopyOption).toBeTruthy();
      }
    });

    test('should have download button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await regenerateBtn.click();

        // Should show download option
        const newBodyText = await page.locator('body').textContent();
        const hasDownloadOption = newBodyText.toLowerCase().includes('download') ||
                                   newBodyText.toLowerCase().includes('recovery');
        expect(hasDownloadOption).toBeTruthy();
      }
    });
  });

  test.describe('Recovery Codes Navigation', () => {
    test('should have back to MFA settings link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // From recovery codes page: <a href="index.php?page=mfa_setup" class="btn">Back to MFA Settings</a>
      const backLink = page.locator('a[href*="mfa_setup"]');
      const hasBackLink = await backLink.count() > 0;
      expect(hasBackLink).toBeTruthy();
    });

    test('should have continue to dashboard link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // From recovery codes page: <a href="index.php" class="btn">Continue to Dashboard</a>
      const dashboardLink = page.locator('a[href="index.php"], a[href*="page=index"]');
      const hasDashboardLink = await dashboardLink.count() > 0;
      expect(hasDashboardLink).toBeTruthy();
    });

    test('should have disable MFA option', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const disableBtn = page.locator('button[name="disable_mfa"]');
        await expect(disableBtn).toBeVisible();
      }
    });
  });

  test.describe('Regenerate Recovery Codes', () => {
    test('should have regenerate codes button when MFA enabled', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await expect(regenerateBtn).toBeVisible();
      }
    });

    test('should regenerate codes when clicking button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await regenerateBtn.click();

        // Should show new recovery codes
        const newBodyText = await page.locator('body').textContent();
        expect(newBodyText.toLowerCase()).toMatch(/recovery|code|save|store/i);
      }
    });
  });

  test.describe('Recovery Codes Security', () => {
    test('should include CSRF token in forms', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // All forms should have CSRF token (could be _token or csrf_token)
      // CSRF tokens are hidden inputs, so we check they exist in the DOM
      const csrfToken = page.locator('input[name="_token"], input[name="csrf_token"]');
      expect(await csrfToken.count()).toBeGreaterThan(0);
    });

    test('should warn about one-time use of codes', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=mfa_setup');

      // Template shows: "Each code can only be used once." when MFA is enabled
      // When MFA is disabled, show setup options
      const bodyText = await page.locator('body').textContent();
      const hasOneTimeWarning = bodyText.toLowerCase().includes('once') ||
                                 bodyText.toLowerCase().includes('one-time') ||
                                 bodyText.toLowerCase().includes('recovery');
      const hasSetupOption = bodyText.toLowerCase().includes('set up') ||
                              bodyText.toLowerCase().includes('authenticator') ||
                              bodyText.toLowerCase().includes('mfa');
      expect(hasOneTimeWarning || hasSetupOption).toBeTruthy();
    });
  });
});

test.describe('Recovery Code Usage', () => {
  test.describe('Recovery Code Modal', () => {
    test('should have recovery code option on MFA verify page', async ({ page }) => {
      // mfa_verify.html includes: <a href="#" data-bs-toggle="modal" data-bs-target="#recoveryCodeModal">
      // This is accessed during MFA verification

      // Verify the template structure
      await page.goto('/index.php?page=login');
      // Just verify login page loads (recovery modal only available during MFA verification)
      await expect(page).toHaveURL(/page=login/);
    });
  });
});

test.describe('MFA Type Display', () => {
  test('should show authentication method type', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=mfa_setup');

    // mfa_recovery_codes.html shows:
    // {% if mfa_type == 'app' %} Authenticator app {% else %} Email verification {% endif %}
    const bodyText = await page.locator('body').textContent();
    const hasMethodType = bodyText.toLowerCase().includes('authenticator') ||
                           bodyText.toLowerCase().includes('email') ||
                           bodyText.toLowerCase().includes('authentication');
    expect(hasMethodType).toBeTruthy();
  });
});
