/**
 * Zone helper functions for Playwright tests
 *
 * These functions provide reusable zone utilities for Poweradmin E2E tests.
 * Adapted for master branch modern URLs.
 *
 * Isolation API (writes go to throwaway objects, never to seeded fixtures):
 *   uniqueName(prefix, testInfo?)            -> 'e2e-<prefix>-w<worker>-<rand>' (DNS label)
 *   uniqueZoneName(prefix, testInfo?, suffix)-> '<uniqueName>.example.com'
 *   createZone(page, name, type)             -> zone id; throws on any failure
 *   createTempZone(page, opts)               -> { id, name }; zone + apex NS + optional records
 *   addRecord(page, zoneId, record)          -> adds one record, throws on refusal
 *   deleteZoneById / deleteZoneByName        -> true when deleted, false when no zone to delete
 * Most specs should use the tempZone / workerZone fixtures from fixtures/test-fixtures.js.
 */

import zones from '../fixtures/zones.json' with { type: 'json' };

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
  // Verify the zone name matches before returning the ID. IDN zones are shown in
  // Unicode, so the fixture's displayName counts as a match as well.
  const displayName = Object.values(zones).find(z => z.name === zoneName)?.displayName;
  const wanted = [zoneName, displayName].filter(Boolean).map(n => n.toLowerCase());
  const resultRows = page.locator('table tbody tr');
  const rowCount = await resultRows.count();

  for (let i = 0; i < rowCount; i++) {
    const row = resultRows.nth(i);
    const rowText = ((await row.textContent()) || '').toLowerCase();

    if (wanted.some(n => rowText.includes(n))) {
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
 * Build a DNS-safe, lowercase name that is unique across workers and reruns.
 *
 * @param {string} prefix - Short purpose tag, e.g. 'crud'
 * @param {import('@playwright/test').TestInfo} [testInfo] - Supplies the worker index
 * @returns {string} e.g. 'e2e-crud-w1-k3f9a2x' (at most 63 characters)
 */
export function uniqueName(prefix, testInfo) {
  const worker = testInfo?.workerIndex ?? process.env.TEST_WORKER_INDEX ?? 0;
  const stamp = Date.now().toString(36).slice(-5);
  const rand = Math.random().toString(36).slice(2, 6);
  const tag = String(prefix).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  return `e2e-${tag}-w${worker}-${stamp}${rand}`.slice(0, 63);
}

/**
 * Unique zone name under a suffix the zone form accepts even with strict_tld_check on.
 *
 * @param {string} prefix
 * @param {import('@playwright/test').TestInfo} [testInfo]
 * @param {string} [suffix='example.com']
 * @returns {string} e.g. 'e2e-crud-w1-k3f9a2x.example.com'
 */
export function uniqueZoneName(prefix, testInfo, suffix = 'example.com') {
  return `${uniqueName(prefix, testInfo)}.${suffix}`;
}

/**
 * Text of the visible error/warning flash messages on the current page.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
export async function errorMessages(page) {
  const texts = await page
    .locator('[data-testid="system-message"].alert-danger, [data-testid="system-message"].alert-warning')
    .allTextContents();
  return texts.map(t => t.trim()).join(' | ');
}

/**
 * Create a zone and return its ID. Throws (never returns null) when the form
 * is refused, the redirect does not happen or the new zone cannot be found.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} domainName - Domain name for the zone
 * @param {string} type - Zone type ('master' or 'slave')
 * @returns {Promise<string>} - Zone ID
 */
export async function createZone(page, domainName, type = 'master') {
  const isSlave = type === 'slave';
  await page.goto(isSlave ? '/zones/add/slave' : '/zones/add/master');

  await page.locator('#domain').fill(domainName);
  if (isSlave) {
    await page.locator('#slave_master').fill('192.168.1.1');
  }

  await page.locator('button[name="submit"]').click();

  // Success redirects to the zone list; a refusal re-renders the add form
  try {
    await page.waitForURL(url => /\/zones\/(forward|reverse)/.test(url.pathname), { timeout: 15000 });
  } catch {
    const reason = await errorMessages(page);
    throw new Error(`createZone(${domainName}) was refused: ${reason || 'no redirect to the zone list'}`);
  }

  const id = await findZoneIdByName(page, domainName);
  if (!id) {
    throw new Error(`createZone(${domainName}) was accepted but the zone was not found afterwards`);
  }
  return id;
}

/**
 * Add one record through /zones/{id}/records/add. Throws when the app refuses it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @param {{name?: string, type: string, content: string, ttl?: string|number, prio?: string|number}} record
 *        name '' or '@' is the zone apex
 * @returns {Promise<void>}
 */
export async function addRecord(page, zoneId, record) {
  await page.goto(`/zones/${zoneId}/records/add`);

  await page.locator('input[name="records[0][name]"]').fill(record.name ?? '');
  await page.locator('select[name="records[0][type]"]').selectOption(record.type);
  await page.locator('input[name="records[0][content]"]').fill(record.content);
  if (record.ttl !== undefined) {
    await page.locator('input[name="records[0][ttl]"]').fill(String(record.ttl));
  }
  if (record.prio !== undefined) {
    await page.locator('input[name="records[0][prio]"]').fill(String(record.prio));
  }

  await page.locator('button[name="commit"]').click();

  try {
    await page.waitForURL(url => !url.pathname.endsWith('/records/add'), { timeout: 15000 });
  } catch {
    const reason = await errorMessages(page);
    throw new Error(`addRecord(${record.type} ${record.name ?? ''} ${record.content}) on zone ${zoneId} was refused: ${reason || 'still on the add form'}`);
  }

  const reason = await errorMessages(page);
  if (reason) {
    throw new Error(`addRecord(${record.type} ${record.name ?? ''} ${record.content}) on zone ${zoneId} failed: ${reason}`);
  }
}

/**
 * Create a throwaway zone that is ready for DNSSEC signing and record tests.
 *
 * @param {import('@playwright/test').Page} page - Admin page
 * @param {object} [opts]
 * @param {string} [opts.name] - Zone name; defaults to uniqueZoneName('tmp')
 * @param {string} [opts.type='master']
 * @param {boolean} [opts.withApexNs=true] - Add apex NS ns1.example.com (signing is refused without one)
 * @param {Array<object>} [opts.records=[]] - Extra records, see addRecord()
 * @param {import('@playwright/test').TestInfo} [opts.testInfo] - Used for the default name
 * @returns {Promise<{id: string, name: string}>}
 */
export async function createTempZone(page, opts = {}) {
  const { type = 'master', withApexNs = true, records = [], testInfo } = opts;
  const name = opts.name ?? uniqueZoneName('tmp', testInfo);

  const id = await createZone(page, name, type);

  try {
    if (withApexNs && type !== 'slave') {
      await addRecord(page, id, { name: '', type: 'NS', content: 'ns1.example.com' });
    }
    for (const record of records) {
      await addRecord(page, id, record);
    }
  } catch (error) {
    // Do not leave a half-built zone behind; the original error is what matters
    await deleteZoneById(page, id).catch(() => {});
    throw error;
  }

  return { id, name };
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
/**
 * Resolve a record ID from the zone edit page.
 *
 * The page edits records inline and carries no per-record edit link, so the ID
 * comes from the input names (`record[<id>][...]`) rather than from an href.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @returns {Promise<string|null>} a non-SOA record ID when the zone has one, else the first ID
 */
export async function firstRecordIdOnZone(page, zoneId) {
  await page.goto(`/zones/${zoneId}/edit`);
  // API backend ids encode name, type and content, so accept anything up to the bracket
  const rows = await page
    .locator('[name^="record["][name$="[type]"]')
    .evaluateAll(nodes => nodes.map(n => ({ name: n.getAttribute('name'), type: n.value })));

  let fallback = null;
  for (const row of rows) {
    const match = row.name && row.name.match(/record\[([^\]]+)\]/);
    if (!match) {
      continue;
    }
    // Saving the SOA rewrites the serial, and on the API backend that changes the record id
    if (row.type !== 'SOA') {
      return match[1];
    }
    fallback = fallback ?? match[1];
  }

  return fallback;
}

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

/**
 * Whether a zone is listed at all, wherever the pagination puts it.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneName - Zone name to look for
 * @returns {Promise<boolean>} - True when the zone still exists
 */
export async function zoneExists(page, zoneName) {
  return (await findZoneIdByName(page, zoneName)) !== null;
}

/**
 * Open the forward zone list page that shows a zone, walking the letter pages.
 *
 * The list is paginated, so a zone a test just created is regularly absent from
 * the first page. Use this only when the test has to act on the row (tick its
 * checkbox); for a plain presence check zoneExists() is far cheaper.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} zoneName - Zone name to look for
 * @param {number} maxPages - Safety bound on how many pages to walk
 * @returns {Promise<boolean>} - True when a list page showing the zone is open
 */
export async function openZoneListPageFor(page, zoneName, maxPages = 40) {
  const letter = zoneName.charAt(0).toLowerCase();

  for (let pageNumber = 1; pageNumber <= maxPages; pageNumber++) {
    await page.goto(`/zones/forward?letter=${letter}&start=${pageNumber}`);

    if (await page.locator(`tr:has-text("${zoneName}")`).count() > 0) {
      return true;
    }

    // An empty page means the walk ran past the last one
    if (await page.locator('table tbody tr').count() === 0) {
      return false;
    }
  }

  return false;
}

/**
 * Delete a zone through its own confirmation page.
 *
 * Going straight to the zone id avoids hunting for the row in the paginated list.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string|number} zoneId - Zone ID to delete
 * @returns {Promise<boolean>} - True when deleted, false when the zone has no delete page; throws when the delete is refused
 */
export async function deleteZoneById(page, zoneId) {
  await page.goto(`/zones/${zoneId}/delete`);

  const confirm = page.locator('[data-testid="confirm-delete-zone"]');
  if (await confirm.count() === 0) {
    return false;
  }

  await confirm.click();

  // Success leaves the confirmation page for the zone list; a refusal stays on it
  try {
    await page.waitForURL(url => !url.pathname.endsWith(`/zones/${zoneId}/delete`), { timeout: 15000 });
  } catch {
    throw new Error(`deleting zone ${zoneId} was refused: ${await errorMessages(page) || 'still on the confirmation page'}`);
  }

  const reason = await errorMessages(page);
  if (reason) {
    throw new Error(`deleting zone ${zoneId} failed: ${reason}`);
  }

  return true;
}

/**
 * Delete a zone by name; a signed zone is deleted directly (verified in the
 * smoke run). Returns false when no such zone exists.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} zoneName
 * @returns {Promise<boolean>}
 */
export async function deleteZoneByName(page, zoneName) {
  const id = await findZoneIdByName(page, zoneName);
  return id ? deleteZoneById(page, id) : false;
}

/**
 * Ports of the instances configured with dns.backend = 'api'
 */
export const API_MODE_PORTS = ['8083', '8084', '8085'];

/**
 * Whether a base URL points at an API-backend instance.
 *
 * Zone list columns whose data comes from PowerDNS rather than the local
 * database are rendered without a sort link there, so tests asserting those
 * links have to skip.
 *
 * @param {string} baseURL - Base URL under test
 * @returns {boolean} - True when the instance runs the API backend
 */
export function isApiModeInstance(baseURL) {
  return API_MODE_PORTS.some((port) => (baseURL || '').includes(`:${port}`));
}

// Export zones fixture for direct access
export { zones };
