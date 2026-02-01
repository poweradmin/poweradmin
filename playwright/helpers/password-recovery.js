/**
 * Password Recovery Helper Functions
 */

/**
 * Check if password recovery is enabled in the application
 * @param {Page} page - Playwright page object
 * @returns {Promise<boolean>} Whether password recovery is enabled
 */
export async function isPasswordRecoveryEnabled(page) {
  // Navigate to login page and check for forgot password link
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  // Look for forgot password link on login page
  const forgotLink = page.locator('a[href*="forgot"], a:has-text("Forgot password"), a:has-text("forgot")');
  return await forgotLink.count() > 0;
}

/**
 * Navigate to the forgot password page
 * @param {Page} page - Playwright page object
 */
export async function goToForgotPasswordPage(page) {
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  const forgotLink = page.locator('a[href*="forgot"], a:has-text("Forgot password"), a:has-text("forgot")').first();
  if (await forgotLink.count() > 0) {
    await forgotLink.click();
    await page.waitForLoadState('networkidle');
  } else {
    // Try direct navigation
    await page.goto('/forgot-password');
    await page.waitForLoadState('networkidle');
  }
}
