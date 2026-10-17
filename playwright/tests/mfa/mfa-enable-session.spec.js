/**
 * Enabling MFA mid-session must not ask for the code again on the next page (#1737).
 * Runs on a throwaway user with a TOTP code computed from the secret the setup page shows.
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

test.describe('MFA enabled during a session', () => {
  test.describe.configure({ mode: 'serial' });

  const username = `mfa-session-${Date.now()}`;
  const password = 'TestP@ssw0rd123';
  let userPage = null;

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

  test('should enable MFA with the code from the setup secret', async () => {
    await userPage.goto('/mfa/setup');
    await userPage.locator('button[name="setup_app"]').click();
    const secret = await userPage.locator('#secret-key').inputValue();

    await userPage.locator('#verification_code').fill(totp(secret));
    await userPage.locator('button[name="verify_app"]').click();

    await expect(userPage.locator('[data-testid="system-message"]')).toContainText(/MFA has been enabled/i);
  });

  test('should not ask for the code again on the next page', async () => {
    await userPage.goto('/');

    await expect(userPage).not.toHaveURL(/mfa\/verify/);
    await expect(userPage.locator('#mfa_code')).toHaveCount(0);
  });

  test('should not open MFA setup for a sign-in still waiting for the code', async ({ browser }) => {
    const pending = await browser.newPage();
    await pending.goto('/login');
    await pending.locator('input[name="username"]').fill(username);
    await pending.locator('input[name="password"]').fill(password);
    await pending.locator('button[type="submit"]').first().click();
    await expect(pending).toHaveURL(/mfa\/verify/);

    await pending.goto('/mfa/setup');

    await expect(pending).not.toHaveURL(/mfa\/setup/);
    await expect(pending.locator('button[name="setup_app"]')).toHaveCount(0);
    await pending.close();
  });
});
