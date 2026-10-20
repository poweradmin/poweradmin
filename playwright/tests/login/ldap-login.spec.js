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

  // The refusal names LDAP in every language, which tells it apart from a local password check
  test('should refuse a wrong LDAP password through the LDAP check', async ({ page }) => {
    await login(page, users.ldapUser.username, 'wrong-password');
    await expect(page).toHaveURL(/.*\/login/);
    await expect(page.locator('.alert-danger').first()).toContainText(/LDAP/);
  });
});
