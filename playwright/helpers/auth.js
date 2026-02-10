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
 * Login and wait for dashboard with retry logic
 *
 * PHP server-side sessions can cause intermittent login failures when
 * multiple tests run concurrently. This function retries on failure.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @param {number} maxRetries - Maximum number of retry attempts
 * @returns {Promise<void>}
 */
export async function loginAndWaitForDashboard(page, username, password, maxRetries = 3) {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    await login(page, username, password);

    // Wait for navigation to complete after form submission
    await page.waitForLoadState('domcontentloaded');

    // Check if login succeeded - URL should indicate dashboard
    const url = page.url();
    if (url.endsWith('/') || url.includes('/?')) {
      // Already on dashboard
      return;
    }

    // Wait for redirect with longer timeout for parallel test stability
    try {
      await page.waitForURL(/\/$|\?/, { timeout: 10000 });
      await page.waitForLoadState('domcontentloaded');
      return; // Success
    } catch {
      // Check if authentication failed (various error messages)
      const errorMessages = [
        'Authentication failed',
        'Invalid CSRF token',
        'Invalid username or password',
        'Session expired'
      ];
      const bodyText = await page.locator('body').textContent();
      const hasRetryableError = errorMessages.some(msg => bodyText.includes(msg)) ||
                                await page.locator('.alert-danger').count() > 0;

      if (hasRetryableError && attempt < maxRetries) {
        // Wait before retry to let session conflicts resolve
        await page.waitForTimeout(1000 * attempt);
        continue;
      }
      if (attempt === maxRetries) {
        throw new Error(`Login failed after ${maxRetries} attempts for user: ${username}`);
      }
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
