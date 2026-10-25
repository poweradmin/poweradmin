/**
 * Account lockout after repeated failed logins, and idle session expiry.
 *
 * Lockout is enabled only on the SSO instance (BASE_URL=http://localhost:8089) and
 * counts per user, so the tests use their own users and never the seeded accounts.
 * Expiry ages the test's own PHP session file inside the app container instead of
 * waiting out session_timeout.
 */

import { execFileSync } from 'node:child_process';
import { test, expect } from '../../fixtures/test-fixtures.js';
import { login } from '../../helpers/auth.js';
import { createUser, deleteUserById, TEMP_USER_PASSWORD } from '../../helpers/users.js';
import { uniqueName } from '../../helpers/zones.js';

const LOCKOUT_ATTEMPTS = 5;
const LOCKOUT_PORT = '8089';
// The login page renders its own flash, not the shared system-message partial
const LOGIN_ERROR = '[data-testid="session-error"].alert-danger';

// SQL instances whose PHP sessions live in the named container's /tmp
const SESSION_CONTAINERS = { 8080: 'pwa-mysql-sql', 8081: 'pwa-pgsql-sql', 8082: 'pwa-sqlite-sql', 8089: 'pwa-mysql-sql-sso' };

function sessionContainerFor(baseURL) {
  const container = SESSION_CONTAINERS[new URL(baseURL).port];
  if (!container) {
    return null;
  }
  try {
    execFileSync('docker', ['exec', container, 'true'], { stdio: 'ignore' });
    return container;
  } catch {
    return null;
  }
}

test.describe('Account lockout', () => {
  test.beforeEach(({ baseURL }) => {
    test.skip(new URL(baseURL).port !== LOCKOUT_PORT, `lockout is enabled only on the instance on port ${LOCKOUT_PORT}`);
  });

  test('locks the account after repeated failures and refuses the right password', async ({ adminPage, browser, baseURL }, testInfo) => {
    const user = await createUser(adminPage, uniqueName('lock', testInfo).slice(0, 40), 'No Access');
    const context = await browser.newContext({ baseURL });
    try {
      const page = await context.newPage();

      for (let attempt = 1; attempt <= LOCKOUT_ATTEMPTS; attempt++) {
        await login(page, user.username, 'Wrong-Password-1');
        await expect(page).toHaveURL(/\/login/);
        await expect(page.locator(LOGIN_ERROR)).toBeVisible();
      }

      await login(page, user.username, TEMP_USER_PASSWORD);
      await expect(page).toHaveURL(/\/login/);
      await expect(page.locator(LOGIN_ERROR)).toContainText('Account is temporarily locked');
    } finally {
      await context.close();
      await deleteUserById(adminPage, user.id);
    }
  });

  test('a user below the limit still signs in', async ({ adminPage, browser, baseURL }, testInfo) => {
    const user = await createUser(adminPage, uniqueName('nolock', testInfo).slice(0, 40), 'No Access');
    const context = await browser.newContext({ baseURL });
    try {
      const page = await context.newPage();
      for (let attempt = 1; attempt < LOCKOUT_ATTEMPTS; attempt++) {
        await login(page, user.username, 'Wrong-Password-1');
        await expect(page.locator(LOGIN_ERROR)).toBeVisible();
      }

      await login(page, user.username, TEMP_USER_PASSWORD);
      await expect(page).not.toHaveURL(/\/login/);
    } finally {
      await context.close();
      await deleteUserById(adminPage, user.id);
    }
  });
});

test.describe('Idle session expiry', () => {
  test('a session idle past session_timeout is ended on the next request', async ({ adminPage, browser, baseURL }, testInfo) => {
    const container = sessionContainerFor(baseURL);
    test.skip(!container, 'needs docker access to the SQL instance container that stores the PHP sessions');

    const user = await createUser(adminPage, uniqueName('idle', testInfo).slice(0, 40), 'No Access');
    const context = await browser.newContext({ baseURL });
    try {
      const page = await context.newPage();
      await login(page, user.username, TEMP_USER_PASSWORD);
      await expect(page).not.toHaveURL(/\/login/);

      const sessionId = (await context.cookies()).find(c => c.name === 'PHPSESSID')?.value;
      expect(sessionId, 'the login must set a PHPSESSID cookie').toMatch(/^[A-Za-z0-9,-]+$/);

      // Rewind the last-activity stamp a day; the user id stays, so only the idle check can end it
      const file = `/tmp/sess_${sessionId}`;
      execFileSync('docker', ['exec', container, 'sed', '-i', '-E', 's/lastmod\\|i:[0-9]+;/lastmod|i:1000000000;/', file]);
      const aged = execFileSync('docker', ['exec', container, 'cat', file]).toString();
      expect(aged, 'the session file must carry the rewound stamp').toContain('lastmod|i:1000000000;');

      await page.goto('/zones/forward');
      await expect(page).toHaveURL(/\/login/);
      await expect(page.locator(LOGIN_ERROR)).toContainText('Session expired');
    } finally {
      await context.close();
      await deleteUserById(adminPage, user.id);
    }
  });
});
