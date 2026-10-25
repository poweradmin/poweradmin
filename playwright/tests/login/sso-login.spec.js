/**
 * OIDC and SAML sign-in against the devcontainer mock IdPs (mock-oidc, mock-saml).
 *
 * Runs only on the SSO instance (BASE_URL=http://localhost:8089), whose settings map
 * the IdP group pa-managers to the Zone Manager template and pa-viewers to the
 * Viewers group. The mock OIDC server issues whatever claims the test types in.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { storedUserTemplate, deleteUserById } from '../../helpers/users.js';
import { errorMessages, uniqueName } from '../../helpers/zones.js';

const SSO_PORT = '8089';
const LOGIN_ERROR = '[data-testid="session-error"].alert-danger';

test.describe.configure({ mode: 'serial', timeout: 90_000 });

test.beforeEach(({ baseURL }) => {
  test.skip(new URL(baseURL).port !== SSO_PORT, `needs the SSO instance on port ${SSO_PORT}`);
});

/** Sign in through the mock OIDC provider with the given claims. */
async function oidcLogin(page, claims) {
  await page.goto('/login');
  // The redirect to the IdP never fires the load event Playwright's click waits for
  await page.locator('[data-testid="oidc-login-mock"]').click({ noWaitAfter: true });
  await page.locator('input[name="username"]').fill(claims.sub);
  await page.locator('textarea[name="claims"]').fill(JSON.stringify(claims));
  await page.locator('input[type="submit"], button[type="submit"]').first().click();
  await page.waitForURL(url => url.port === SSO_PORT, { timeout: 15000 });
}

/** Sign in through the mock SAML IdP with one of its fixture users. */
async function samlLogin(page, username) {
  await page.goto('/login');
  // The redirect to the IdP never fires the load event Playwright's click waits for
  await page.locator('[data-testid="saml-login-mock"]').click({ noWaitAfter: true });
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(`${username}pass`);
  // The IdP answers with a self-posting form, so the click's own navigation wait never settles
  await page.locator('form[name="f"] button').click({ noWaitAfter: true });
  await page.waitForURL(url => url.port === SSO_PORT, { timeout: 30000 });
}

async function expectSignedIn(page) {
  await expect(page, `the sign-in must complete: ${await errorMessages(page)}`).not.toHaveURL(/\/login/);
  await page.goto('/zones/forward');
  await expect(page).not.toHaveURL(/\/login/);
}

async function expectSignedOut(page) {
  await page.goto('/zones/forward');
  await expect(page).toHaveURL(/\/login/);
}

/** Ids and usernames of the accounts whose email matches. */
async function usersByEmail(adminPage, email) {
  await adminPage.goto(`/users?search=${encodeURIComponent(email)}`);
  // An ignored filter would page the full list and make "no such account" vacuous
  await expect(adminPage.locator('#search')).toHaveValue(email);
  await expect(adminPage.locator(`tbody tr:has(input[name$="[email]"]):not(:has(input[name$="[email]"][value="${email}"]))`)).toHaveCount(0);
  return adminPage.locator(`tr:has(input[value="${email}"])`).evaluateAll(rows => rows.map(row => ({
    id: row.querySelector('a[href$="/edit"]').getAttribute('href').match(/\/users\/(\d+)\/edit/)[1],
    username: row.querySelector('input[name$="[username]"]')?.value ?? '',
  })));
}

// A takeover regression would put the claim's email on a seeded account; never delete those
const SEEDED_USERNAMES = new Set(Object.values(users).map(u => u.username));

/** Delete the throwaway accounts carrying any of the given emails. */
async function deleteAccounts(adminPage, ...emails) {
  for (const email of emails) {
    for (const account of await usersByEmail(adminPage, email)) {
      if (!SEEDED_USERNAMES.has(account.username)) {
        await deleteUserById(adminPage, account.id);
      }
    }
  }
}

async function expectGroupMember(adminPage, groupName, userId, expected) {
  await adminPage.goto('/groups');
  await adminPage.locator(`tr:has-text("${groupName}") a[href$="/members"]`).first().click();
  await expect(adminPage.locator(`#remove-form .member-checkbox[value="${userId}"]`)).toHaveCount(expected ? 1 : 0);
}

// The mock IdP pages pull web fonts from the internet; a slow fetch stalls the navigation
const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1']);

async function withUserContext(browser, baseURL, fn) {
  const context = await browser.newContext({ baseURL });
  await context.route('**/*', route => (LOCAL_HOSTS.has(new URL(route.request().url()).hostname) ? route.continue() : route.abort()));
  try {
    return await fn(await context.newPage());
  } finally {
    await context.close();
  }
}

test.describe('OIDC sign-in', () => {
  test('the login page offers both providers', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('[data-testid="oidc-login-mock"]')).toBeVisible();
    await expect(page.locator('[data-testid="saml-login-mock"]')).toBeVisible();
  });

  test('provisions a new user with the mapped template and group, and finds it by subject on the next sign-in', async ({ adminPage, browser, baseURL }, testInfo) => {
    const name = uniqueName('oidc', testInfo).slice(0, 30);
    const claims = { sub: name, preferred_username: name, email: `${name}@example.org`, name: 'OIDC Test', groups: ['pa-managers', 'pa-viewers'] };
    // A changed email leaves only the stored subject link to find the account by
    const renamed = { ...claims, email: `${name}-new@example.org`, preferred_username: `${name}-new` };

    try {
      await withUserContext(browser, baseURL, async page => {
        await oidcLogin(page, claims);
        await expectSignedIn(page);
      });
      const [account] = await usersByEmail(adminPage, claims.email);
      expect(account?.username).toBe(name);
      expect(await storedUserTemplate(adminPage, account.id)).toBe('Zone Manager');
      await expectGroupMember(adminPage, 'Viewers', account.id, true);

      await withUserContext(browser, baseURL, async page => {
        await oidcLogin(page, renamed);
        await expectSignedIn(page);
      });
      expect(await usersByEmail(adminPage, renamed.email)).toEqual([{ id: account.id, username: name }]);
      expect(await usersByEmail(adminPage, claims.email)).toHaveLength(0);
    } finally {
      await deleteAccounts(adminPage, claims.email, renamed.email);
    }
  });

  test('a username taken by a local account gets a new account, not the local one', async ({ adminPage, browser, baseURL }, testInfo) => {
    const sub = uniqueName('oidc-viewer', testInfo).slice(0, 30);
    const claims = { sub, preferred_username: 'viewer', email: `${sub}@example.org`, name: 'Not The Viewer', groups: [] };

    try {
      await withUserContext(browser, baseURL, async page => {
        await oidcLogin(page, claims);
        await expectSignedIn(page);
      });
      const accounts = await usersByEmail(adminPage, claims.email);
      expect(accounts).toHaveLength(1);
      expect(accounts[0].username).not.toBe('viewer');
      expect(await storedUserTemplate(adminPage, accounts[0].id)).toBe('No Access');
    } finally {
      await deleteAccounts(adminPage, claims.email);
    }
  });

  test('a callback whose state was swapped is refused even with a valid code', async ({ adminPage, browser, baseURL }, testInfo) => {
    const name = uniqueName('oidc-state', testInfo).slice(0, 30);
    const claims = { sub: name, preferred_username: name, email: `${name}@example.org`, name: 'OIDC State', groups: [] };

    try {
      await withUserContext(browser, baseURL, async page => {
        // Rewrite only the state in the IdP's redirect back, so its code stays valid; a request
        // that follows a redirect is never routed, so the IdP's own 302 is the place to do it
        await page.route('**/default/authorize?**', async route => {
          if (route.request().method() !== 'POST') {
            return route.continue();
          }
          const response = await route.fetch({ maxRedirects: 0 });
          const location = new URL(response.headers()['location']);
          location.searchParams.set('state', 'forged-state');
          return route.fulfill({ response, headers: { ...response.headers(), location: location.toString() } });
        });
        await oidcLogin(page, claims);
        await expect(page).toHaveURL(/\/login/);
        await expect(page.locator(LOGIN_ERROR)).toContainText('Invalid state parameter');
        await expectSignedOut(page);
      });
      expect(await usersByEmail(adminPage, claims.email)).toHaveLength(0);
    } finally {
      await deleteAccounts(adminPage, claims.email);
    }
  });

  test('logging out goes through the provider\'s end-session endpoint and ends the session', async ({ adminPage, browser, baseURL }, testInfo) => {
    const name = uniqueName('oidc-out', testInfo).slice(0, 30);
    const claims = { sub: name, preferred_username: name, email: `${name}@example.org`, name: 'OIDC Logout', groups: [] };

    try {
      await withUserContext(browser, baseURL, async page => {
        await oidcLogin(page, claims);
        await expectSignedIn(page);
        // The local-only fallback also lands on /login, so the provider hop is what proves RP logout
        const visited = [];
        page.on('request', request => visited.push(new URL(request.url())));
        await page.goto('/logout');
        await page.waitForURL(url => url.port === SSO_PORT, { timeout: 15000 });
        const endSession = visited.find(url => url.port === '9080' && url.pathname === '/default/endsession');
        expect(endSession, 'logout must redirect to the provider end-session endpoint').toBeTruthy();
        expect(endSession.searchParams.get('post_logout_redirect_uri')).toBe(`http://localhost:${SSO_PORT}/login`);
        await expectSignedOut(page);
      });
    } finally {
      await deleteAccounts(adminPage, claims.email);
    }
  });
});

test.describe('SAML sign-in', () => {
  test('provisions the IdP user with the mapped template and group', async ({ adminPage, browser, baseURL }) => {
    const email = 'samlmanager@example.net';
    // A rerun after an aborted one must start from a fresh account
    await deleteAccounts(adminPage, email);

    try {
      await withUserContext(browser, baseURL, async page => {
        await samlLogin(page, 'samlmanager');
        await expectSignedIn(page);
      });
      const accounts = await usersByEmail(adminPage, email);
      expect(accounts.map(a => a.username)).toEqual(['samlmanager']);
      expect(await storedUserTemplate(adminPage, accounts[0].id)).toBe('Zone Manager');
      await expectGroupMember(adminPage, 'Viewers', accounts[0].id, true);
    } finally {
      await deleteAccounts(adminPage, email);
    }
  });

  test('a response this browser never asked for is refused at the ACS', async ({ page }) => {
    await page.goto('/login');
    const samlResponse = Buffer.from('<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"/>').toString('base64');
    // The RelayState names the provider, so the post gets past the no-provider gate to the unsolicited-response check
    const relayState = Buffer.from(JSON.stringify({ provider: 'mock' })).toString('base64');
    await page.evaluate(([value, relay]) => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/saml/acs';
      const field = document.createElement('input');
      field.name = 'SAMLResponse';
      field.value = value;
      form.appendChild(field);
      const relayField = document.createElement('input');
      relayField.name = 'RelayState';
      relayField.value = relay;
      form.appendChild(relayField);
      document.body.appendChild(form);
      form.submit();
    }, [samlResponse, relayState]);
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator(LOGIN_ERROR)).toContainText('the sign-in was not started from this browser');
    await expectSignedOut(page);
  });
});
