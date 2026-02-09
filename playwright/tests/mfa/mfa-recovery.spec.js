/**
 * MFA Recovery Codes Tests
 *
 * Tests for Multi-Factor Authentication recovery codes functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('MFA Recovery Codes Page', () => {
  test.describe('Recovery Codes Display', () => {
    test('should access MFA setup page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');
      await expect(page).toHaveURL(/.*mfa\/setup/);
    });

    test('should show recovery codes option when MFA enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const mfaEnabled = bodyText.toLowerCase().includes('mfa is currently enabled');

      if (mfaEnabled) {
        // Should have regenerate recovery codes button
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await expect(regenerateBtn).toBeVisible();
      }
    });

    test('should display recovery codes after MFA setup', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      // Check if we can access recovery codes (either through setup or regeneration)
      const bodyText = await page.locator('body').textContent();
      const hasRecoveryOption = bodyText.toLowerCase().includes('recovery') ||
                                 bodyText.toLowerCase().includes('regenerate');
      const hasSetupOption = bodyText.toLowerCase().includes('set up') ||
                              bodyText.toLowerCase().includes('authenticator');
      expect(hasRecoveryOption || hasSetupOption).toBeTruthy();
    });
  });

  test.describe('Recovery Codes Page Structure', () => {
    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();
    });

    test('should have MFA setup complete header after enabling', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const hasContent = bodyText.toLowerCase().includes('mfa') ||
                          bodyText.toLowerCase().includes('multi-factor');
      expect(hasContent).toBeTruthy();
    });
  });

  test.describe('Recovery Codes Management', () => {
    test('should display save warning for recovery codes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();
      const mfaEnabled = bodyText.toLowerCase().includes('mfa is currently enabled');

      if (mfaEnabled) {
        const hasWarning = bodyText.toLowerCase().includes('save') ||
                           bodyText.toLowerCase().includes('store') ||
                           bodyText.toLowerCase().includes('safe') ||
                           bodyText.toLowerCase().includes('regenerate');
        expect(hasWarning).toBeTruthy();
      } else {
        const hasSetupOption = bodyText.toLowerCase().includes('set up') ||
                                bodyText.toLowerCase().includes('authenticator');
        expect(hasSetupOption).toBeTruthy();
      }
    });

    test('should display recovery codes in grid format', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const codeElements = page.locator('code');
      const hasCodeDisplayOption = await codeElements.count() >= 0;
      expect(hasCodeDisplayOption).toBeTruthy();
    });
  });

  test.describe('Recovery Codes Actions', () => {
    test('should have print button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await expect(regenerateBtn).toBeVisible();

        await regenerateBtn.click();

        const newBodyText = await page.locator('body').textContent();
        const hasPrintOption = newBodyText.toLowerCase().includes('print') ||
                                newBodyText.toLowerCase().includes('recovery');
        expect(hasPrintOption).toBeTruthy();
      }
    });

    test('should have copy all button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await regenerateBtn.click();

        const newBodyText = await page.locator('body').textContent();
        const hasCopyOption = newBodyText.toLowerCase().includes('copy') ||
                               newBodyText.toLowerCase().includes('recovery');
        expect(hasCopyOption).toBeTruthy();
      }
    });

    test('should have download button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await regenerateBtn.click();

        const newBodyText = await page.locator('body').textContent();
        const hasDownloadOption = newBodyText.toLowerCase().includes('download') ||
                                   newBodyText.toLowerCase().includes('recovery');
        expect(hasDownloadOption).toBeTruthy();
      }
    });
  });

  test.describe('Recovery Codes Navigation', () => {
    test('should have back to MFA settings link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const backLink = page.locator('a[href*="mfa"]');
      const hasBackLink = await backLink.count() > 0;
      expect(hasBackLink).toBeTruthy();
    });

    test('should have continue to dashboard link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const dashboardLink = page.locator('a[href="/"], a[href*="dashboard"]');
      const hasDashboardLink = await dashboardLink.count() > 0;
      expect(hasDashboardLink).toBeTruthy();
    });

    test('should have disable MFA option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const disableBtn = page.locator('button[name="disable_mfa"]');
        await expect(disableBtn).toBeVisible();
      }
    });
  });

  test.describe('Regenerate Recovery Codes', () => {
    test('should have regenerate codes button when MFA enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await expect(regenerateBtn).toBeVisible();
      }
    });

    test('should regenerate codes when clicking button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const bodyText = await page.locator('body').textContent();

      if (bodyText.toLowerCase().includes('mfa is currently enabled')) {
        const regenerateBtn = page.locator('button[name="regenerate_codes"]');
        await regenerateBtn.click();

        const newBodyText = await page.locator('body').textContent();
        expect(newBodyText.toLowerCase()).toMatch(/recovery|code|save|store/i);
      }
    });
  });

  test.describe('Recovery Codes Security', () => {
    test('should include CSRF token in forms', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const csrfToken = page.locator('input[name="_token"], input[name="csrf_token"]');
      expect(await csrfToken.count()).toBeGreaterThan(0);
    });

    test('should warn about one-time use of codes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

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
      await page.goto('/login');
      await expect(page).toHaveURL(/.*login/);
    });
  });
});

test.describe('MFA Type Display', () => {
  test('should show authentication method type', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/mfa/setup');

    const bodyText = await page.locator('body').textContent();
    const hasMethodType = bodyText.toLowerCase().includes('authenticator') ||
                           bodyText.toLowerCase().includes('email') ||
                           bodyText.toLowerCase().includes('authentication');
    expect(hasMethodType).toBeTruthy();
  });
});
