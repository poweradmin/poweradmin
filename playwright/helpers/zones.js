/**
 * Zone helper functions for Playwright tests
 *
 * These functions provide reusable zone utilities for Poweradmin E2E tests.
 * Adapted for master branch modern URLs.
 */

import zones from '../fixtures/zones.json' assert { type: 'json' };

/**
 * Check if a zone name is a reverse DNS zone
 *
 * @param {string} zoneName - Zone name to check
 * @returns {boolean} - True if reverse zone
 */
export function isReverseZone(zoneName) {
  return zoneName.endsWith('.in-addr.arpa') || zoneName.endsWith('.ip6.arpa');
}

/**
 * Extract ID from a URL path
 * Supports both legacy (?id=123) and modern (/zones/123/edit) URL patterns
 *
 * @param {string} href - URL to extract ID from
 * @returns {string|null} - ID or null if not found
 */
function extractIdFromUrl(href) {
  if (!href) return null;

  // Modern URL pattern: /zones/123/edit, /zones/123
  const modernMatch = href.match(/\/zones\/(\d+)(?:\/edit|\/delete|$)/);
  if (modernMatch) return modernMatch[1];

  // General modern pattern: /123/edit
  const generalMatch = href.match(/\/(\d+)(?:\/edit|\/delete|$)/);
  if (generalMatch) return generalMatch[1];

  // Legacy URL pattern: ?id=123 or &id=123
  const legacyMatch = href.match(/[?&]id=(\d+)/);
  if (legacyMatch) return legacyMatch[1];

  return null;
}

/**
 * Find zone ID using the search page
 * Useful when the zone is not visible on the first page of zone list
 * or when the displayed name differs from the arpa format (IPv6 zones)
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneName - Zone name to search for
 * @returns {Promise<string|null>} - Zone ID or null if not found
 */
async function findZoneIdBySearch(page, zoneName) {
  await page.goto('/search');
  await page.waitForLoadState('networkidle');

  const queryInput = page.locator('#query');
  if (await queryInput.count() === 0) return null;

  // Search with the full zone name - the database stores arpa format
  await queryInput.fill(zoneName);

  // Ensure "Zones" checkbox is checked
  const zonesCheck = page.locator('#zones_check');
  if (await zonesCheck.count() > 0 && !(await zonesCheck.isChecked())) {
    await zonesCheck.check();
  }

  // Submit search
  await page.locator('button[name="do_search"]').click();
  await page.waitForLoadState('networkidle');

  // Search results show zone name as text in <td> and edit link in the same <tr>
  // Verify the zone name matches before returning the ID
  const resultRows = page.locator('table tbody tr');
  const rowCount = await resultRows.count();

  for (let i = 0; i < rowCount; i++) {
    const row = resultRows.nth(i);
    const rowText = (await row.textContent()) || '';

    // Check if this row contains our zone name (case-insensitive)
    if (rowText.toLowerCase().includes(zoneName.toLowerCase())) {
      const editLink = row.locator('a[href*="/zones/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const id = extractIdFromUrl(href);
        if (id) return id;
      }
    }
  }

  return null;
}

/**
 * Find zone ID by zone name
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneName - Zone name to search for (punycode or UTF-8)
 * @returns {Promise<string|null>} - Zone ID or null if not found
 */
export async function findZoneIdByName(page, zoneName) {
  // Determine which zone list to check based on zone name
  // Use letter=all for forward zones to ensure we find zones starting with any letter
  const listPage = isReverseZone(zoneName)
    ? '/zones/reverse?reverse_type=all'
    : '/zones/forward?letter=all';

  await page.goto(listPage);

  // Wait for table to load
  await page.waitForSelector('table', { timeout: 5000 }).catch(() => null);

  // Find the row containing the zone name
  let row = page.locator(`tr:has-text("${zoneName}")`);

  // If not found, try searching by display name from fixtures
  // Handles IDN zones (xn-- punycode) and IPv6 reverse zones (displayed as human-readable prefix)
  if (await row.count() === 0) {
    const zoneEntry = Object.values(zones).find(z => z.name === zoneName);
    if (zoneEntry && zoneEntry.displayName) {
      row = page.locator(`tr:has-text("${zoneEntry.displayName}")`);
    }
  }

  if (await row.count() > 0) {
    // For reverse zones, use data-testid to target Actions column edit buttons,
    // not "Associated Forward Zones" links which also match /zones/*/edit
    const editLink = isReverseZone(zoneName)
      ? row.locator('a[data-testid^="edit-zone-"]').first()
      : row.locator('a[href*="/edit"]').first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const id = extractIdFromUrl(href);
      if (id) return id;
    }
  }

  // Fallback: use search page to find zone (handles pagination and display name differences)
  return await findZoneIdBySearch(page, zoneName);
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
 * @param {boolean} excludeReverse - Whether to exclude reverse DNS zones (default: true)
 * @returns {Promise<{id: string, name: string}|null>} - Zone info or null if none found
 */
export async function findAnyZoneId(page, excludeReverse = true) {
  await page.goto('/zones/forward?letter=all');

  // Wait for table to load
  await page.waitForSelector('table', { timeout: 5000 }).catch(() => null);

  // Find edit links in table (modern URLs: /zones/123/edit)
  const editLinks = page.locator('table a[href*="/zones/"][href*="/edit"]');
  const count = await editLinks.count();

  // Find first suitable zone (excluding reverse zones if requested)
  for (let i = 0; i < count; i++) {
    const editLink = editLinks.nth(i);
    const row = editLink.locator('xpath=ancestor::tr');
    const rowText = await row.textContent().catch(() => '');

    // Skip reverse zones (in-addr.arpa, ip6.arpa) if excludeReverse is true
    if (excludeReverse && (rowText.includes('.in-addr.arpa') || rowText.includes('.ip6.arpa'))) {
      continue;
    }

    const href = await editLink.getAttribute('href');
    const id = extractIdFromUrl(href);

    if (id) {
      // Get zone name from the row
      const cells = row.locator('td');
      const cellCount = await cells.count();
      let zoneName = null;

      for (let j = 0; j < cellCount && j < 5; j++) {
        const cellText = await cells.nth(j).textContent().catch(() => '');
        if (cellText && cellText.includes('.') && cellText.trim().length > 3) {
          zoneName = cellText.trim();
          break;
        }
      }

      return { id, name: zoneName };
    }
  }

  // Fallback to first edit link if no suitable zone found
  if (count === 0) {
    return null;
  }

  const editLink = editLinks.first();
  const href = await editLink.getAttribute('href');
  const id = extractIdFromUrl(href);

  if (!id) {
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
    id,
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
    ? '/zones/add/slave'
    : '/zones/add/master';

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
  await page.goto(`/zones/${zoneId}/edit`);

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
  const actionLink = row.locator('a[href*="/records/"][href*="/edit"], a[href*="record_id="], a[href*="id="]').first();
  if (await actionLink.count() === 0) {
    return null;
  }

  const href = await actionLink.getAttribute('href');

  // Try modern URL pattern first: /records/123/edit
  const modernMatch = href?.match(/\/records\/(\d+)(?:\/edit|\/delete|$)/);
  if (modernMatch) return modernMatch[1];

  // Fallback to legacy patterns
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
 * Get a zone ID for testing, resolving stable fixture zones by name first.
 *
 * Prefers manager-zone.example.com, then admin-zone.example.com (both seeded by
 * global-setup and resolved by name, not list order) so the result is
 * deterministic. findAnyZoneId is only a last resort when neither named fixture
 * is present, e.g. against a non-fixture environment.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<string|null>} - Zone ID or null if no zones available
 */
export async function getZoneIdForTest(page) {
  // Prefer named fixture zones so the choice does not depend on list order
  let zoneId = await getTestZoneId(page, 'manager');

  if (!zoneId) {
    zoneId = await getTestZoneId(page, 'admin');
  }

  if (!zoneId) {
    // Last resort for non-fixture environments: any available zone
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

/**
 * Find a zone list column index by its exact header text
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} headerText - Exact header text to match
 * @returns {Promise<number>} - Column index, or -1 when the column is absent
 */
export async function getColumnIndex(page, headerText) {
  return page.evaluate((target) => {
    const headers = Array.from(document.querySelectorAll('thead th'));
    return headers.findIndex(h => h.innerText.trim() === target);
  }, headerText);
}

// Export zones fixture for direct access
export { zones };
