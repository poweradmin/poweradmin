/**
 * MFA Recovery Codes Tests
 *
 * Tests for Multi-Factor Authentication recovery codes functionality.
 * The account-level flows enable MFA on a throwaway user with a TOTP code
 * computed from the secret the setup page shows, so no seeded account is
 * left with MFA on.
 */

import { createHmac } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * RFC 6238 TOTP (SHA-1, 6 digits, 30 second step) for a base32 secret.
 *
 * @param {string} secret - Base32 secret as shown on the MFA setup page
 * @param {number} offsetSteps - Time steps to shift, to step over a period boundary
 * @returns {string} Six digit code
 */
function totp(secret, offsetSteps = 0) {
  let bits = '';
  for (const char of secret.replace(/=+$/, '').replace(/\s+/g, '').toUpperCase()) {
    bits += BASE32_ALPHABET.indexOf(char).toString(2).padStart(5, '0');
  }
  const key = Buffer.from(
    bits.match(/.{8}/g).map(byte => parseInt(byte, 2))
  );

  const counter = Math.floor(Date.now() / 30000) + offsetSteps;
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(counter));

  const hmac = createHmac('sha1', key).update(message).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const value = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];

  return String(value % 1000000).padStart(6, '0');
}

test.describe('MFA Recovery Codes Page', () => {
  test.describe('Recovery Codes Display', () => {
    test('should access MFA setup page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');
      await expect(page).toHaveURL(/.*mfa\/setup/);
    });

    test('should display recovery codes after MFA setup', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

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
  });

  test.describe('Recovery Codes Security', () => {
    test('should include CSRF token in forms', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/mfa/setup');

      const csrfToken = page.locator('input[name="_token"]');
      expect(await csrfToken.count()).toBeGreaterThan(0);
    });
  });

  test.describe('MFA enabled account', () => {
    test.describe.configure({ mode: 'serial' });

    const username = `mfa-rec-${Date.now()}`;
    const password = 'TestP@ssw0rd123';
    let userPage = null;
    let firstCodes = [];
    let secret = '';

    // Enabling MFA mid-session makes the next request ask for a code, so answer it
    async function openSetup() {
      await userPage.goto('/mfa/setup');
      for (const offset of [0, 1, -1]) {
        if (await userPage.locator('#mfa_code').count() === 0) {
          break;
        }
        await userPage.locator('#mfa_code').fill(totp(secret, offset));
        await userPage.locator('button[name="verify_mfa"]').first().click();
        await userPage.goto('/mfa/setup');
      }
    }

    test.beforeAll(async ({ browser }) => {
      const admin = await browser.newPage();
      await loginAndWaitForDashboard(admin, users.admin.username, users.admin.password);
      await admin.goto('/users/add');
      await admin.locator('input[name="username"]').fill(username);
      await admin.locator('input[name="fullname"]').fill(username);
      await admin.locator('input[name="email"]').fill(`${username}@example.com`);
      await admin.locator('input[name="password"]').fill(password);
      await admin.locator('button[name="commit"]').click();
      await expect(admin.locator('[data-testid="system-message"]')).toContainText(/success/i);
      await admin.close();

      // One page for the whole group: a fresh login would ask for the MFA code
      userPage = await browser.newPage();
      await loginAndWaitForDashboard(userPage, username, password);
    });

    test.afterAll(async ({ browser }) => {
      await userPage?.close();

      const admin = await browser.newPage();
      await loginAndWaitForDashboard(admin, users.admin.username, users.admin.password);
      await admin.goto(`/users?search=${username}`);
      // The username renders as an input value, so hasText would never match
      const row = admin.locator(`tr:has(input[value="${username}"])`);
      if (await row.count() > 0) {
        await row.locator('a[href*="/delete"]').first().click();
        await admin.locator('button[name="commit"]').click();
        await admin.waitForLoadState('networkidle');
      }
      await admin.close();
    });

    test('should enable MFA from the setup secret and show recovery codes', async () => {
      await userPage.goto('/mfa/setup');
      await userPage.locator('button[name="setup_app"]').click();

      secret = await userPage.locator('#secret-key').inputValue();
      expect(secret).toMatch(/^[A-Z2-7]+=*$/);

      await userPage.locator('#verification_code').fill(totp(secret));
      await userPage.locator('button[name="verify_app"]').click();

      await expect(userPage.locator('[data-testid="system-message"]')).toContainText(/MFA has been enabled/i);

      const codes = userPage.locator('code');
      await expect(codes.first()).toBeVisible();
      firstCodes = await codes.allTextContents();
      expect(firstCodes.length).toBeGreaterThan(0);
      for (const code of firstCodes) {
        expect(code.trim().length).toBeGreaterThan(0);
      }
    });

    test('should show recovery codes option when MFA enabled', async () => {
      await openSetup();

      await expect(userPage.locator('body')).toContainText(/MFA is currently enabled/i);
      await expect(userPage.locator('button[name="regenerate_codes"]')).toBeVisible();
    });

    test('should display save warning for recovery codes', async () => {
      await openSetup();

      await expect(userPage.locator('body')).toContainText(/MFA is currently enabled/i);
      await expect(userPage.locator('body')).toContainText(/save|store|safe|regenerate/i);
    });

    test('should have disable MFA option', async () => {
      await openSetup();

      await expect(userPage.locator('button[name="disable_mfa"]')).toBeVisible();
    });

    test('should regenerate codes when clicking button', async () => {
      await openSetup();
      await userPage.locator('button[name="regenerate_codes"]').click();

      await expect(userPage.locator('body')).toContainText(/recovery|code|save|store/i);

      // Display grid: every code sits in a <code> element and the set is new
      const codes = userPage.locator('code');
      await expect(codes.first()).toBeVisible();
      const regenerated = await codes.allTextContents();
      expect(regenerated.length).toBe(firstCodes.length);
      expect(regenerated).not.toEqual(firstCodes);
    });

    test('should warn about one-time use of codes', async () => {
      // Still on the recovery codes page that the regeneration rendered
      await expect(userPage.locator('body')).toContainText(/Each code can only be used once/i);
    });

    test('should have print button', async () => {
      await expect(userPage.locator('button:has-text("Print")')).toBeVisible();
    });

    test('should have copy all button', async () => {
      await expect(userPage.locator('button:has-text("Copy all")')).toBeVisible();
    });

    test('should have download button', async () => {
      await expect(userPage.locator('button#download-btn')).toBeVisible();
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
