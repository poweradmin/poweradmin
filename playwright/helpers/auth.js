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
  await page.goto('/login');
  await page.fill('[data-testid="username-input"]', username);
  await page.fill('[data-testid="password-input"]', password);
  await page.click('[data-testid="login-button"]');
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
  await page.waitForURL('/');
}

/**
 * Logout from Poweradmin
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function logout(page) {
  // Look for logout button/link and click it
  const logoutButton = page.locator('a:has-text("Logout"), button:has-text("Logout")').first();
  if (await logoutButton.isVisible()) {
    await logoutButton.click();
  }
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
