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
 * Same, for the API, which answers denial as JSON rather than a rendered page.
 *
 * @param {import('@playwright/test').Response} response
 */
export function expectApiAccessDenied(response) {
  expect(response.status()).toBe(403);
}
