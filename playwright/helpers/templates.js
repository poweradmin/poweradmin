/**
 * Template helper functions for Playwright tests
 *
 * These functions provide reusable template utilities for Poweradmin E2E tests.
 */

/**
 * Find template ID by name
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} templateName - Template name to search for
 * @returns {Promise<string|null>} - Template ID or null if not found
 */
export async function findTemplateIdByName(page, templateName) {
  await page.goto('/index.php?page=list_zone_templ');

  // Wait for table to load
  await page.waitForSelector('table', { timeout: 5000 }).catch(() => null);

  // Find the row containing the template name
  const row = page.locator(`tr:has-text("${templateName}")`);

  if (await row.count() === 0) {
    return null;
  }

  // Find edit link and extract ID
  const editLink = row.locator('a[href*="edit_zone_templ"]').first();
  if (await editLink.count() === 0) {
    return null;
  }

  const href = await editLink.getAttribute('href');
  const match = href?.match(/id=(\d+)/);

  return match ? match[1] : null;
}

/**
 * Create a zone template
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} name - Template name
 * @param {string} description - Template description (optional)
 * @returns {Promise<string|null>} - Template ID or null if creation failed
 */
export async function createTemplate(page, name, description = '') {
  await page.goto('/index.php?page=add_zone_templ');

  // Fill template name
  const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
  await nameField.fill(name);

  // Fill description if provided
  if (description) {
    const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
    if (await descField.count() > 0) {
      await descField.fill(description);
    }
  }

  // Submit the form
  await page.locator('button[type="submit"], input[type="submit"]').first().click();

  // Wait for page to process
  await page.waitForLoadState('networkidle');

  // Try to find the template ID
  return await findTemplateIdByName(page, name);
}

/**
 * Ensure a template exists and return its ID
 * Creates the template if it doesn't exist
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} name - Template name
 * @param {string} description - Template description (optional)
 * @returns {Promise<string|null>} - Template ID or null if both find and create failed
 */
export async function ensureTemplateExists(page, name, description = '') {
  // First try to find existing template
  let templateId = await findTemplateIdByName(page, name);

  if (templateId) {
    return templateId;
  }

  // Template doesn't exist, create it
  return await createTemplate(page, name, description);
}

/**
 * Find permission template ID by name
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} templateName - Permission template name to search for
 * @returns {Promise<string|null>} - Template ID or null if not found
 */
export async function findPermTemplateIdByName(page, templateName) {
  await page.goto('/index.php?page=list_perm_templ');

  // Wait for table to load
  await page.waitForSelector('table', { timeout: 5000 }).catch(() => null);

  // Find the row containing the template name
  const row = page.locator(`tr:has-text("${templateName}")`);

  if (await row.count() === 0) {
    return null;
  }

  // Find edit link and extract ID
  const editLink = row.locator('a[href*="edit_perm_templ"]').first();
  if (await editLink.count() === 0) {
    return null;
  }

  const href = await editLink.getAttribute('href');
  const match = href?.match(/id=(\d+)/);

  return match ? match[1] : null;
}

/**
 * Create a permission template
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} name - Template name
 * @param {string} description - Template description (optional)
 * @returns {Promise<string|null>} - Template ID or null if creation failed
 */
export async function createPermTemplate(page, name, description = '') {
  await page.goto('/index.php?page=add_perm_templ');

  // Fill template name
  const nameField = page.locator('input[name*="name"]').first();
  await nameField.fill(name);

  // Fill description if provided
  if (description) {
    const descField = page.locator('input[name*="descr"], textarea[name*="descr"]').first();
    if (await descField.count() > 0) {
      await descField.fill(description);
    }
  }

  // Submit the form
  await page.locator('button[type="submit"], input[type="submit"]').first().click();

  // Wait for page to process
  await page.waitForLoadState('networkidle');

  // Try to find the template ID
  return await findPermTemplateIdByName(page, name);
}

/**
 * Ensure a permission template exists and return its ID
 * Creates the template if it doesn't exist
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} name - Template name
 * @param {string} description - Template description (optional)
 * @returns {Promise<string|null>} - Template ID or null if both find and create failed
 */
export async function ensurePermTemplateExists(page, name, description = '') {
  // First try to find existing template
  let templateId = await findPermTemplateIdByName(page, name);

  if (templateId) {
    return templateId;
  }

  // Template doesn't exist, create it
  return await createPermTemplate(page, name, description);
}
