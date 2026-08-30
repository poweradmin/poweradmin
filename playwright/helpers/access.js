/**
 * Access-denial assertions.
 *
 * BaseController::checkPermission() renders only the header and footer with an
 * error system message, so a denied page carries the alert and none of its own
 * content. Substring-matching the body cannot express that: /permissions/templates
 * contains the word "permission" whether or not access was granted, so a check
 * accepting that word passes even when the permission gate is broken.
 */

import { expect } from '@playwright/test';

const DENIAL_ALERT = '[data-testid="system-message"].alert-danger';

/**
 * Assert the page rendered a permission denial rather than its own content.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [contentSelector] Selector for content only a permitted user sees.
 */
export async function expectAccessDenied(page, contentSelector) {
  await expect(page.locator(DENIAL_ALERT)).toBeVisible();

  if (contentSelector) {
    await expect(page.locator(contentSelector)).toHaveCount(0);
  }
}

/**
 * Same, for endpoints that answer denial as JSON. Any path containing "/api/"
 * takes that branch, which includes the web page /settings/api/logs.
 *
 * @param {import('@playwright/test').Response} response
 * @param {import('@playwright/test').Page} page
 * @param {string} [contentSelector] Selector for content only a permitted user sees.
 */
export async function expectAccessDeniedJson(response, page, contentSelector) {
  expect(response.status()).toBe(403);

  if (contentSelector) {
    await expect(page.locator(contentSelector)).toHaveCount(0);
  }
}
