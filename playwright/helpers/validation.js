/**
 * Validation helper functions for Playwright tests
 *
 * These functions provide reusable error checking and validation utilities
 * for Poweradmin E2E tests.
 */

import { expect } from '@playwright/test';

/**
 * Check if page contains an error message
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string[]} patterns - Patterns to check for (default: ['error', 'invalid', 'denied'])
 * @returns {Promise<boolean>}
 */
export async function hasErrorMessage(page, patterns = ['error', 'invalid', 'denied']) {
  const bodyText = await page.locator('body').textContent();
  return patterns.some(p => bodyText.toLowerCase().includes(p));
}

/**
 * Assert that page has no fatal errors
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function expectNoFatalError(page) {
  const bodyText = await page.locator('body').textContent();
  expect(bodyText).not.toMatch(/fatal|exception/i);
}

/**
 * Assert that an error alert is visible
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function expectErrorVisible(page) {
  await expect(page.locator('.alert-danger, .error, [data-testid*="error"]').first()).toBeVisible();
}

/**
 * Assert that a success alert is visible
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function expectSuccessVisible(page) {
  await expect(page.locator('.alert-success, .success, [data-testid*="success"]').first()).toBeVisible();
}

/**
 * Check if page shows access denied message
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<boolean>}
 */
export async function hasAccessDenied(page) {
  const bodyText = await page.locator('body').textContent();
  return bodyText.toLowerCase().includes('denied') ||
         bodyText.toLowerCase().includes('permission') ||
         bodyText.toLowerCase().includes('not allowed');
}

/**
 * Assert that form validation error is shown
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} fieldName - Name of the field with error
 * @returns {Promise<void>}
 */
export async function expectValidationError(page, fieldName) {
  const invalidFeedback = page.locator(`.invalid-feedback:near(input[name*="${fieldName}"])`);
  await expect(invalidFeedback).toBeVisible();
}

/**
 * Check if current URL indicates an error state
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {boolean}
 */
export function isErrorUrl(page) {
  const url = page.url();
  return url.includes('error') || url.includes('denied');
}

/**
 * Assert that operation completed successfully (no errors, stayed on form, or redirected)
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} expectedUrl - Expected URL pattern after success
 * @returns {Promise<void>}
 */
export async function expectOperationSuccess(page, expectedUrl) {
  await expectNoFatalError(page);
  await expect(page).toHaveURL(new RegExp(expectedUrl));
}
