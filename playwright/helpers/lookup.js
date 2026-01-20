/**
 * Table lookup helper functions for Playwright tests
 *
 * These functions provide reusable utilities for finding items in tables
 * and extracting IDs from links.
 *
 * Adapted for master branch modern URLs (e.g., /zones/123/edit)
 */

/**
 * Extract ID from a URL path
 * Supports both legacy (?id=123) and modern (/zones/123/edit) URL patterns
 *
 * @param {string} href - URL to extract ID from
 * @returns {string|null} - ID or null if not found
 */
function extractIdFromUrl(href) {
  if (!href) return null;

  // Modern URL pattern: /zones/123/edit, /records/123/edit, etc.
  const modernMatch = href.match(/\/(\d+)(?:\/edit|\/delete|$)/);
  if (modernMatch) return modernMatch[1];

  // Legacy URL pattern: ?id=123 or &id=123
  const legacyMatch = href.match(/[?&]id=(\d+)/);
  if (legacyMatch) return legacyMatch[1];

  // Record ID pattern: ?record_id=123 or &record_id=123
  const recordMatch = href.match(/[?&]record_id=(\d+)/);
  if (recordMatch) return recordMatch[1];

  return null;
}

/**
 * Find an ID in a table by matching row text
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} text - Text to search for in table rows
 * @param {string} linkPattern - URL pattern to match in links (default: '/edit')
 * @returns {Promise<string|null>} - ID or null if not found
 */
export async function findIdInTableByText(page, text, linkPattern = '/edit') {
  const row = page.locator(`tr:has-text("${text}")`);
  if (await row.count() === 0) return null;

  const link = row.locator(`a[href*="${linkPattern}"]`).first();
  if (await link.count() === 0) return null;

  const href = await link.getAttribute('href');
  return extractIdFromUrl(href);
}

/**
 * Find a record ID in a table by matching record name and optionally type
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} recordName - Record name to search for
 * @param {string|null} recordType - Optional record type to filter by (e.g., 'A', 'MX')
 * @returns {Promise<string|null>} - Record ID or null if not found
 */
export async function findRecordIdInTable(page, recordName, recordType = null) {
  let rowSelector = `tr:has-text("${recordName}")`;
  if (recordType) {
    rowSelector = `tr:has-text("${recordName}"):has-text("${recordType}")`;
  }

  const row = page.locator(rowSelector).first();
  if (await row.count() === 0) return null;

  // Try modern URL patterns first, then legacy
  const actionLink = row.locator('a[href*="/edit"], a[href*="record_id="], a[href*="id="]').first();
  if (await actionLink.count() === 0) return null;

  const href = await actionLink.getAttribute('href');
  return extractIdFromUrl(href);
}

/**
 * Get all IDs from a table column
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} linkPattern - URL pattern to match in links
 * @returns {Promise<string[]>} - Array of IDs
 */
export async function getAllIdsFromTable(page, linkPattern = '/edit') {
  const links = page.locator(`a[href*="${linkPattern}"]`);
  const count = await links.count();
  const ids = [];

  for (let i = 0; i < count; i++) {
    const href = await links.nth(i).getAttribute('href');
    const id = extractIdFromUrl(href);
    if (id) {
      ids.push(id);
    }
  }

  return ids;
}

/**
 * Check if a row exists in a table with the given text
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} text - Text to search for
 * @returns {Promise<boolean>}
 */
export async function rowExistsInTable(page, text) {
  const row = page.locator(`tr:has-text("${text}")`);
  return (await row.count()) > 0;
}

/**
 * Get cell text from a table row by column index
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} rowText - Text to identify the row
 * @param {number} columnIndex - Zero-based column index
 * @returns {Promise<string|null>} - Cell text or null if not found
 */
export async function getCellTextFromRow(page, rowText, columnIndex) {
  const row = page.locator(`tr:has-text("${rowText}")`).first();
  if (await row.count() === 0) return null;

  const cell = row.locator('td').nth(columnIndex);
  if (await cell.count() === 0) return null;

  return await cell.textContent();
}

/**
 * Count rows in a table (excluding header)
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} tableSelector - CSS selector for the table (default: 'table')
 * @returns {Promise<number>}
 */
export async function countTableRows(page, tableSelector = 'table') {
  const rows = page.locator(`${tableSelector} tbody tr, ${tableSelector} tr:not(:first-child)`);
  return await rows.count();
}
