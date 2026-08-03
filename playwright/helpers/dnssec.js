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
