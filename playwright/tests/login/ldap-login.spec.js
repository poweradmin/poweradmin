import { test, expect } from '../../fixtures/test-fixtures.js';
import { login } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Needs the ldap container running; every devcontainer instance enables LDAP and imports these users
test.describe('LDAP Login', () => {
  test('should log in an LDAP user with the LDAP password', async ({ page }) => {
    await login(page, users.ldapUser.username, users.ldapUser.password);
    await expect(page).not.toHaveURL(/.*\/login/);
  });

  test('should log in a second LDAP user with a different template', async ({ page }) => {
    await login(page, users.ldapUser2.username, users.ldapUser2.password);
    await expect(page).not.toHaveURL(/.*\/login/);
  });

  // The page shows one generic failure for every method, so the audit entry tells the
  // LDAP refusal apart from a local password check
  test('should refuse a wrong LDAP password through the LDAP check', async ({ adminPage, browser, baseURL }) => {
    // Export rows carry no id, so count the LDAP wrong-password entries before and after;
    // testuser2 because other specs fail testuser and would race the count
    const ldapRefusals = async () => {
      const response = await adminPage.request.get(`/users/logs?name=${users.ldapUser2.username}&event_type=login_failed&export=json`);
      expect(response.ok()).toBeTruthy();
      return (await response.json()).filter(entry => entry.auth_method === 'ldap' && entry.reason === 'wrong_password').length;
    };
    const before = await ldapRefusals();

    // adminPage is the default page, so the attempt needs a browser of its own
    const context = await browser.newContext({ baseURL });
    try {
      const page = await context.newPage();
      await login(page, users.ldapUser2.username, 'wrong-password');
      await expect(page).toHaveURL(/.*\/login/);
      await expect(page.locator('[data-testid="session-error"].alert-danger')).toContainText('Authentication failed!');
    } finally {
      await context.close();
    }

    expect(await ldapRefusals()).toBe(before + 1);
  });
});
