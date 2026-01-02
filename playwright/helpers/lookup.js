/**
 * Table lookup helper functions for Playwright tests
 *
 * These functions provide reusable utilities for finding items in tables
 * and extracting IDs from links.
 */

/**
 * Find an ID in a table by matching row text
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} text - Text to search for in table rows
 * @param {string} linkPattern - URL pattern to match in links (default: 'page=edit')
 * @returns {Promise<string|null>} - ID or null if not found
 */
export async function findIdInTableByText(page, text, linkPattern = 'page=edit') {
  const row = page.locator(`tr:has-text("${text}")`);
  if (await row.count() === 0) return null;

  const link = row.locator(`a[href*="${linkPattern}"]`).first();
  if (await link.count() === 0) return null;

  const href = await link.getAttribute('href');
  return href?.match(/id=(\d+)/)?.[1] || null;
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

  const actionLink = row.locator('a[href*="record_id="], a[href*="id="]').first();
  if (await actionLink.count() === 0) return null;

  const href = await actionLink.getAttribute('href');
  const match = href?.match(/(?:record_id|id)=(\d+)/);
  return match ? match[1] : null;
}

/**
 * Get all IDs from a table column
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} linkPattern - URL pattern to match in links
 * @returns {Promise<string[]>} - Array of IDs
 */
export async function getAllIdsFromTable(page, linkPattern = 'page=edit') {
  const links = page.locator(`a[href*="${linkPattern}"]`);
  const count = await links.count();
  const ids = [];

  for (let i = 0; i < count; i++) {
    const href = await links.nth(i).getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (match) {
      ids.push(match[1]);
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
