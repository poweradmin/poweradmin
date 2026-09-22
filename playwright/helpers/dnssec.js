/**
 * DNSSEC key helpers.
 *
 * The key-management specs submit the add-key form repeatedly and the devcontainer
 * seed ships no cryptokeys at all, so without a prune every run leaves more keys
 * behind than the last. Capture the ids up front, remove whatever the run added.
 */

/**
 * List the DNSSEC key ids currently shown for a zone.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @returns {Promise<string[]>} Key ids, as strings
 */
export async function listDnssecKeyIds(page, zoneId) {
  await page.goto(`/zones/${zoneId}/dnssec`);

  const hrefs = await page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').evaluateAll(
    links => links.map(a => a.getAttribute('href'))
  );

  return hrefs
    .map(href => href?.match(/\/dnssec\/keys\/(\d+)\/delete/)?.[1])
    .filter(Boolean);
}

/**
 * Add a DNSSEC key to a zone when it has none.
 *
 * The delete tests consume keys, so a rerun of the same file can find the zone
 * empty and fail on a missing delete link rather than on the behaviour it covers.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @returns {Promise<void>}
 */
export async function ensureDnssecKey(page, zoneId) {
  if ((await listDnssecKeyIds(page, zoneId)).length > 0) {
    return;
  }

  await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');
}

/**
 * Delete every key of a zone that is not in keepIds.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @param {string[]} keepIds - Ids that existed before the spec ran
 * @returns {Promise<number>} How many keys were removed
 */
export async function pruneDnssecKeys(page, zoneId, keepIds) {
  const keep = new Set(keepIds.map(String));
  let removed = 0;

  for (const keyId of await listDnssecKeyIds(page, zoneId)) {
    if (keep.has(keyId)) {
      continue;
    }

    await page.goto(`/zones/${zoneId}/dnssec/keys/${keyId}/delete`);
    const confirm = page.locator('form[action*="/delete"] button[type="submit"]').first();
    if (await confirm.count() === 0) {
      continue;
    }
    await confirm.click();
    removed++;
  }

  return removed;
}
