/**
 * Bad-token posts against the flows that skip the shared CSRF check.
 *
 * Login, MFA verify, forgot password, forgot username and reset password
 * opt out of BaseController's token check and validate their own flow token
 * instead. Each test tampers that token and checks the post is refused, with
 * a valid-token control where the flow can be repeated safely.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { login, loginAndWaitForDashboard } from '../../helpers/auth.js';
import { uniqueName } from '../../helpers/zones.js';
import { createUser, deleteUserById } from '../../helpers/users.js';
import { totp } from '../../helpers/mfa.js';
import users from '../../fixtures/users.json' with { type: 'json' };

const FLOW_TOKEN_ERROR = /Invalid security token/i;
// Seeded by .devcontainer/sql/test-extra-data-*.sql for admin@example.com
const SEEDED_RESET_TOKEN = 'e2e-password-reset-token';

test.describe.configure({ timeout: 120_000 });

async function tamper(page, selector) {
  await page.locator(selector).evaluate(el => { el.value = 'invalid-token'; });
}

test.describe('Flow tokens reject a bad value', () => {
  test('login refuses valid credentials posted with a bad token', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL });
    try {
      const page = await context.newPage();
      await page.goto('/login');
      await page.fill('[data-testid="username-input"]', users.admin.username);
      await page.fill('[data-testid="password-input"]', users.admin.password);
      await tamper(page, 'input[name="_token"]');
      await page.click('[data-testid="login-button"]');

      // Waiting for the flash also proves the post reached the server rather than being cancelled
      await expect(page.locator('[data-testid="session-error"]')).toContainText(/Invalid CSRF token/i);
      await expect(page).toHaveURL(/\/login/);

      // Control: the same credentials with the real token sign in
      await login(page, users.admin.username, users.admin.password);
      await expect(page).not.toHaveURL(/\/login/);
    } finally {
      await context.close();
    }
  });

  test('MFA verify refuses a recovery code posted with a bad token', async ({ adminPage, browser, baseURL }, testInfo) => {
    const user = await createUser(adminPage, uniqueName('mfa-tok', testInfo).slice(0, 40), 'No Access');
    const setupContext = await browser.newContext({ baseURL });
    const context = await browser.newContext({ baseURL });
    try {
      const setup = await setupContext.newPage();
      await loginAndWaitForDashboard(setup, user.username, user.password, 3, { fresh: true });
      await setup.goto('/mfa/setup');
      await setup.locator('button[name="setup_app"]').click();
      const secret = await setup.locator('#secret-key').inputValue();
      await setup.locator('#verification_code').fill(totp(secret));
      await setup.locator('button[name="verify_app"]').click();
      await expect(setup.locator('[data-testid="system-message"]')).toContainText(/MFA has been enabled/i);
      const recoveryCode = (await setup.locator('code').first().textContent()).trim();

      const page = await context.newPage();
      await login(page, user.username, user.password);
      await expect(page).toHaveURL(/\/mfa\/verify/);

      // Posted directly: the app-code field's pattern would block a recovery code in the browser
      const postCode = async token => (await page.request.post('/mfa/verify', {
        form: { mfa_token: token, username: user.username, mfa_code: recoveryCode, verify_mfa: '' },
      })).text();

      // The token is checked before the code, so the recovery code is not spent here.
      // A redirect would be followed, so getting the form back means no sign-in happened
      const refused = await postCode('invalid-token');
      expect(refused).toMatch(FLOW_TOKEN_ERROR);
      const freshToken = refused.match(/name="mfa_token" value="([^"]+)"/)?.[1];
      expect(freshToken, 'the refused post should re-render the verify form').toBeTruthy();

      // Control: the same code with the re-rendered token completes the sign-in
      await postCode(freshToken);
      await page.goto('/');
      await expect(page).not.toHaveURL(/\/(mfa\/verify|login)/);
    } finally {
      await setupContext.close();
      await context.close();
      await deleteUserById(adminPage, user.id);
    }
  });

  test('forgot password refuses a request posted with a bad token', async ({ page }) => {
    await page.goto('/password/forgot');
    await page.locator('input[name="email"]').fill('nobody@example.invalid');
    await tamper(page, 'input[name="password_reset_token"]');
    await page.locator('button[type="submit"]').click();

    await expect(page.locator('body')).toContainText(FLOW_TOKEN_ERROR);
  });

  test('forgot username refuses a request posted with a bad token', async ({ page }) => {
    await page.goto('/username/forgot');
    await page.locator('input[name="email"]').fill('nobody@example.invalid');
    await tamper(page, 'input[name="username_recovery_token"]');
    await page.locator('button[type="submit"]').click();

    await expect(page.locator('body')).toContainText(FLOW_TOKEN_ERROR);
  });

  test('reset password refuses a new password posted with a bad token', async ({ page }) => {
    await page.goto(`/password/reset?token=${SEEDED_RESET_TOKEN}`);
    // The seeded token belongs to admin; reusing admin's password keeps the suite's login working if this regresses
    await page.locator('input[name="password"]').fill(users.admin.password);
    await page.locator('input[name="confirm_password"]').fill(users.admin.password);
    await tamper(page, 'input[name="reset_password_token"]');
    await page.locator('button[type="submit"]').click();

    await expect(page.locator('body')).toContainText(FLOW_TOKEN_ERROR);

    // A refused post must leave the reset link usable
    await page.goto(`/password/reset?token=${SEEDED_RESET_TOKEN}`);
    await expect(page.locator('input[name="reset_password_token"]')).toHaveCount(1);
  });
});
