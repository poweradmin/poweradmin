/**
 * Password Recovery helper functions for Playwright tests
 *
 * These functions help test password recovery functionality,
 * handling cases where SMTP may not be configured.
 */

/**
 * Check if password recovery feature is enabled
 * When SMTP is not configured, the feature shows "Password reset functionality is disabled."
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<boolean>} - True if password recovery is available
 */
export async function isPasswordRecoveryEnabled(page) {
  await page.goto('/index.php?page=forgot_password');

  const bodyText = await page.locator('body').textContent();

  // Check if the feature is disabled
  const isDisabled = bodyText.toLowerCase().includes('password reset functionality is disabled') ||
                     bodyText.toLowerCase().includes('password recovery is disabled') ||
                     bodyText.toLowerCase().includes('functionality is disabled');

  return !isDisabled;
}

/**
 * Check if the password reset form is visible
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<boolean>} - True if form is visible
 */
export async function isResetFormVisible(page) {
  const emailInput = page.locator('input[name="email"], input[type="email"]');
  return await emailInput.count() > 0;
}
