/**
 * Form interaction helper functions for Playwright tests
 *
 * These functions provide reusable form utilities for Poweradmin E2E tests,
 * reducing code duplication and improving maintainability.
 */

/**
 * Submit a form and wait for page to load
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function submitForm(page) {
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');
}

/**
 * Fill an input field by name pattern
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} namePattern - Name attribute pattern to match
 * @param {string} value - Value to fill
 * @returns {Promise<void>}
 */
export async function fillField(page, namePattern, value) {
  await page.locator(`input[name*="${namePattern}"]`).first().fill(value);
}

/**
 * Select a dropdown option by name pattern
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} namePattern - Name attribute pattern to match
 * @param {string} value - Option value to select
 * @returns {Promise<void>}
 */
export async function selectDropdown(page, namePattern, value) {
  await page.locator(`select[name*="${namePattern}"]`).first().selectOption(value);
}

/**
 * Fill an input field by its label (preferred - resilient locator)
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} label - Label text to match
 * @param {string} value - Value to fill
 * @returns {Promise<void>}
 */
export async function fillByLabel(page, label, value) {
  await page.getByLabel(label).fill(value);
}

/**
 * Select a dropdown option by its label (preferred - resilient locator)
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} label - Label text to match
 * @param {string} value - Option value to select
 * @returns {Promise<void>}
 */
export async function selectByLabel(page, label, value) {
  await page.getByLabel(label).selectOption(value);
}

/**
 * Fill an input field by data-testid
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} testId - data-testid attribute value
 * @param {string} value - Value to fill
 * @returns {Promise<void>}
 */
export async function fillByTestId(page, testId, value) {
  await page.getByTestId(testId).fill(value);
}

/**
 * Select a dropdown option by data-testid
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} testId - data-testid attribute value
 * @param {string} value - Option value to select
 * @returns {Promise<void>}
 */
export async function selectByTestId(page, testId, value) {
  await page.getByTestId(testId).selectOption(value);
}

/**
 * Click a button by its visible text
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string|RegExp} name - Button text to match
 * @returns {Promise<void>}
 */
export async function clickButton(page, name) {
  await page.getByRole('button', { name }).click();
}

/**
 * Click a link by its visible text
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string|RegExp} name - Link text to match
 * @returns {Promise<void>}
 */
export async function clickLink(page, name) {
  await page.getByRole('link', { name }).click();
}
