/**
 * Authentication helper functions for Playwright tests
 *
 * These functions provide reusable authentication utilities
 * for Poweradmin E2E tests, equivalent to Cypress custom commands.
 */

/**
 * Login to Poweradmin via UI form (used by login tests that test the form itself)
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @returns {Promise<void>}
 */
export async function login(page, username, password) {
  await page.goto('/login');
  await page.fill('[data-testid="username-input"]', username);
  await page.fill('[data-testid="password-input"]', password);
  await page.click('[data-testid="login-button"]');
}

// Per-process cache of session cookies per username. Each Playwright worker is a
// separate OS process, so cached sessions are never shared across workers.
const sessionCache = new Map();

/**
 * Login and wait for dashboard, reusing a cached server session when available.
 *
 * On cache hit the saved cookies are injected and validated with a single page
 * load; if the server session died (logout test, timeout, restart) it falls
 * back to the original UI form login and refreshes the cache.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @param {number} maxRetries - Maximum number of retry attempts
 * @returns {Promise<void>}
 */
export async function loginAndWaitForDashboard(page, username, password, maxRetries = 3) {
  const cached = sessionCache.get(username);
  if (cached) {
    await page.context().addCookies(cached);
    let reusable = false;
    try {
      await page.goto('/');
      reusable = !page.url().includes('/login');
    } catch {
      // A session another test logged out of can leave the server bouncing
      // between / and /login, which fails the navigation instead of landing
      // on the form; fall through to a fresh login rather than failing here
      reusable = false;
    }
    if (reusable) {
      return;
    }
    sessionCache.delete(username);
    await page.context().clearCookies();
  }
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      await page.goto('/login');
      await page.fill('[data-testid="username-input"]', username);
      await page.fill('[data-testid="password-input"]', password);
      await Promise.all([
        page.waitForURL(url => !url.toString().includes('/login'), { timeout: 10000 }),
        page.click('[data-testid="login-button"]'),
      ]);
      sessionCache.set(username, await page.context().cookies());
      return; // Success
    } catch {
      if (attempt === maxRetries) {
        throw new Error(`Login failed after ${maxRetries} attempts for user: ${username}`);
      }
      await page.waitForTimeout(1000 * attempt);
    }
  }
}

/**
 * Logout from Poweradmin
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function logout(page) {
  // Navigate directly to logout page for reliable logout
  await page.goto('/logout');
  await page.waitForURL(/login/);
}

/**
 * Check if user is logged in
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<boolean>}
 */
export async function isLoggedIn(page) {
  // Check if we're not on the login page
  const currentUrl = page.url();
  return !currentUrl.includes('/login');
}
