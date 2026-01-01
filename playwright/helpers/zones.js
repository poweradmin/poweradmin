/**
 * Zone helper functions for Playwright tests
 *
 * These functions provide reusable zone utilities for Poweradmin E2E tests.
 */

import zones from '../fixtures/zones.json' assert { type: 'json' };

/**
 * Find zone ID by zone name
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneName - Zone name to search for
 * @returns {Promise<string|null>} - Zone ID or null if not found
 */
export async function findZoneIdByName(page, zoneName) {
  await page.goto('/index.php?page=list_zones&letter=all');

  // Wait for table to load
  await page.waitForSelector('table', { timeout: 5000 }).catch(() => null);

  // Find the row containing the zone name
  const row = page.locator(`tr:has-text("${zoneName}")`);

  if (await row.count() === 0) {
    return null;
  }

  // Find edit link and extract ID
  const editLink = row.locator('a[href*="page=edit"]').first();
  if (await editLink.count() === 0) {
    return null;
  }

  const href = await editLink.getAttribute('href');
  const match = href?.match(/id=(\d+)/);

  return match ? match[1] : null;
}

/**
 * Get zone ID for a predefined test zone
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneKey - Key from zones.json (e.g., 'admin', 'manager', 'client')
 * @returns {Promise<string|null>} - Zone ID or null if not found
 */
export async function getTestZoneId(page, zoneKey) {
  const zone = zones[zoneKey];
  if (!zone) {
    throw new Error(`Unknown zone key: ${zoneKey}. Available: ${Object.keys(zones).join(', ')}`);
  }

  return await findZoneIdByName(page, zone.name);
}

/**
 * Find any available zone ID for testing
 * Useful when you just need a zone to work with
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<{id: string, name: string}|null>} - Zone info or null if none found
 */
export async function findAnyZoneId(page) {
  await page.goto('/index.php?page=list_zones&letter=all');

  // Wait for table to load
  await page.waitForSelector('table', { timeout: 5000 }).catch(() => null);

  // Find first edit link - this usually contains the zone name as link text
  const editLink = page.locator('a[href*="page=edit"]').first();

  if (await editLink.count() === 0) {
    return null;
  }

  const href = await editLink.getAttribute('href');
  const match = href?.match(/id=(\d+)/);

  if (!match) {
    return null;
  }

  // Get zone name from the edit link text or look for zone name in the row
  let zoneName = await editLink.textContent().catch(() => null);

  // If link text is empty or just contains non-zone text, try to find zone name in row
  if (!zoneName || zoneName.trim().length < 3 || zoneName.toLowerCase().includes('edit')) {
    // Look for a td that contains a domain-like string
    const row = editLink.locator('xpath=ancestor::tr');
    const cells = row.locator('td');
    const cellCount = await cells.count();

    for (let i = 0; i < cellCount && i < 5; i++) {
      const cellText = await cells.nth(i).textContent().catch(() => '');
      // Look for domain-like text (contains a dot and looks like a zone name)
      if (cellText && cellText.includes('.') && cellText.trim().length > 3) {
        zoneName = cellText.trim();
        break;
      }
    }
  }

  return {
    id: match[1],
    name: zoneName?.trim() || null
  };
}

/**
 * Create a test zone and return its ID
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} domainName - Domain name for the zone
 * @param {string} type - Zone type ('master' or 'slave')
 * @returns {Promise<string|null>} - Zone ID or null if creation failed
 */
export async function createZone(page, domainName, type = 'master') {
  const pageUrl = type === 'slave'
    ? '/index.php?page=add_zone_slave'
    : '/index.php?page=add_zone_master';

  await page.goto(pageUrl);

  // Fill in zone name
  await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
    .first()
    .fill(domainName);

  // For slave zones, add a master IP
  if (type === 'slave') {
    await page.locator('input[name*="master"], input[name*="ip"]')
      .first()
      .fill('192.168.1.1');
  }

  // Submit the form
  await page.locator('button[type="submit"], input[type="submit"]').first().click();

  // Wait for page to process
  await page.waitForLoadState('networkidle');

  // Try to find the zone ID
  return await findZoneIdByName(page, domainName);
}

/**
 * Ensure a zone exists and return its ID
 * Creates the zone if it doesn't exist
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} domainName - Domain name for the zone
 * @param {string} type - Zone type ('master' or 'slave')
 * @returns {Promise<string|null>} - Zone ID or null if both find and create failed
 */
export async function ensureZoneExists(page, domainName, type = 'master') {
  // First try to find existing zone
  let zoneId = await findZoneIdByName(page, domainName);

  if (zoneId) {
    return zoneId;
  }

  // Zone doesn't exist, create it
  return await createZone(page, domainName, type);
}

/**
 * Find a record ID in a zone
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneId - Zone ID
 * @param {string} recordName - Record name to find (can be partial match)
 * @param {string} recordType - Record type (e.g., 'A', 'MX', 'CNAME')
 * @returns {Promise<string|null>} - Record ID or null if not found
 */
export async function findRecordId(page, zoneId, recordName, recordType = null) {
  await page.goto(`/index.php?page=edit&id=${zoneId}`);

  // Build selector for the row
  let rowSelector = `tr:has-text("${recordName}")`;
  if (recordType) {
    rowSelector = `tr:has-text("${recordName}"):has-text("${recordType}")`;
  }

  const row = page.locator(rowSelector).first();

  if (await row.count() === 0) {
    return null;
  }

  // Find edit or delete link to get record ID
  const actionLink = row.locator('a[href*="record_id="], a[href*="id="]').first();
  if (await actionLink.count() === 0) {
    return null;
  }

  const href = await actionLink.getAttribute('href');
  const match = href?.match(/(?:record_id|id)=(\d+)/);

  return match ? match[1] : null;
}

/**
 * Get zone info from zones fixture
 *
 * @param {string} zoneKey - Key from zones.json
 * @returns {object} - Zone info object
 */
export function getZoneInfo(zoneKey) {
  const zone = zones[zoneKey];
  if (!zone) {
    throw new Error(`Unknown zone key: ${zoneKey}. Available: ${Object.keys(zones).join(', ')}`);
  }
  return zone;
}

/**
 * Ensure a test zone from fixtures exists and return its ID
 * Creates the zone if it doesn't exist
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneKey - Key from zones.json (e.g., 'admin', 'manager', 'client')
 * @returns {Promise<string|null>} - Zone ID or null if creation failed
 */
export async function ensureTestZoneExists(page, zoneKey) {
  const zone = zones[zoneKey];
  if (!zone) {
    throw new Error(`Unknown zone key: ${zoneKey}. Available: ${Object.keys(zones).join(', ')}`);
  }

  return await ensureZoneExists(page, zone.name, zone.type.toLowerCase());
}

/**
 * Get a zone ID for testing, trying multiple fallback options
 * Uses manager zone first, then admin, then any available zone
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<string|null>} - Zone ID or null if no zones available
 */
export async function getZoneIdForTest(page) {
  // First try the manager zone
  let zoneId = await getTestZoneId(page, 'manager');

  if (!zoneId) {
    // Fallback to admin zone
    zoneId = await getTestZoneId(page, 'admin');
  }

  if (!zoneId) {
    // Last resort: find any zone
    const anyZone = await findAnyZoneId(page);
    if (anyZone) {
      zoneId = anyZone.id;
    }
  }

  return zoneId;
}

/**
 * Ensure any zone exists for testing
 * Tries to find existing zones first, creates one if none exist
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<string|null>} - Zone ID or null if creation failed
 */
export async function ensureAnyZoneExists(page) {
  // First try to find any existing zone
  const anyZone = await findAnyZoneId(page);
  if (anyZone && anyZone.id) {
    return anyZone.id;
  }

  // No zones exist, create the manager zone
  return await ensureTestZoneExists(page, 'manager');
}

// Export zones fixture for direct access
export { zones };
