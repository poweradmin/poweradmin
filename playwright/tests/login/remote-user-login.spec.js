import { test, expect } from '../../fixtures/test-fixtures.js';

/**
 * Web server authentication (REMOTE_USER).
 *
 * The 8088 devcontainer instance sits behind nginx basic auth and signs in the
 * user nginx verified. remote-alice and remote-bob have no Poweradmin account
 * until their first visit; admin is a local account, which the web server must
 * never take over. All three use the password Poweradmin123.
 *
 * Run with: BASE_URL=http://localhost:8088 npx playwright test remote-user-login --workers=1
 */

const baseUrl = process.env.BASE_URL || 'http://localhost:8080';
const PASSWORD = 'Poweradmin123';

test.describe('Web server authentication', () => {
  test.skip(!baseUrl.includes('8088'), 'Needs the web server authentication instance (port 8088)');
  // The first visit provisions the account, so two tests must not race to create the same one
  test.describe.configure({ mode: 'serial' });

  test.describe('as a web server user', () => {
    test.use({ httpCredentials: { username: 'remote-alice', password: PASSWORD } });

    test('signs the user in without the login form', async ({ page }) => {
      const response = await page.goto('/');
      expect(response.status()).toBe(200);
      await expect(page).not.toHaveURL(/\/login/);
      // Only a signed-in page offers logout
      await expect(page.locator('a[href$="/logout"]')).not.toHaveCount(0);
    });
  });

  test.describe('after logout', () => {
    test.use({ httpCredentials: { username: 'remote-bob', password: PASSWORD } });

    test('stays signed out until the user continues', async ({ page }) => {
      await page.goto('/');
      await expect(page).not.toHaveURL(/\/login/);

      await page.goto('/logout');
      await expect(page).toHaveURL(/\/login/);
      const continueButton = page.getByTestId('remote-user-login');
      await expect(continueButton).toContainText('remote-bob');

      // The web server still sends the user, but logout must not loop back in
      await page.goto('/');
      await expect(page).toHaveURL(/\/login/);

      await continueButton.click();
      await expect(page).not.toHaveURL(/\/login/);
    });
  });

  test.describe('as the web server user of a local account', () => {
    test.use({ httpCredentials: { username: 'admin', password: PASSWORD } });

    test('refuses to sign in a local account with the same name', async ({ page }) => {
      await page.goto('/');
      await expect(page).toHaveURL(/\/login/);
      await expect(page.getByTestId('session-error')).toBeVisible();

      // Not retried on every page until the user asks
      await page.goto('/');
      await expect(page).toHaveURL(/\/login/);
    });
  });
});
