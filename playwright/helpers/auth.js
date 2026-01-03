/**
 * Authentication helper functions for Playwright tests
 *
 * These functions provide reusable authentication utilities
 * for Poweradmin E2E tests, equivalent to Cypress custom commands.
 */

/**
 * Login to Poweradmin
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @returns {Promise<void>}
 */
export async function login(page, username, password) {
  await page.goto('/index.php?page=login');
  // Use Playwright's resilient locator APIs for better reliability
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /go/i }).click();
}

/**
 * Login and wait for dashboard
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @returns {Promise<void>}
 */
export async function loginAndWaitForDashboard(page, username, password) {
  await login(page, username, password);
  // Extended timeout for login - server-side session creation can be slower than regular navigation
  await page.waitForURL(/page=index/, { timeout: 30000 });
  await page.waitForLoadState('domcontentloaded');
}

/**
 * Logout from Poweradmin
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function logout(page) {
  // Navigate directly to logout page for reliable logout
  await page.goto('/index.php?page=logout');
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
  return !currentUrl.includes('page=login');
}
