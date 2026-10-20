/**
 * DNSSEC key helpers.
 *
 * API: listDnssecKeyIds, ensureDnssecKey, addDnssecKey, pruneDnssecKeys,
 *   ensureZoneSigned(page, zoneId)           -> zone has >= 1 ACTIVE key (needs an apex NS)
 *   submitKeyToggle(page, zoneId, keyId)     -> flips active/inactive and asserts it flipped
 * Run these against a throwaway zone (tempZone fixture), never a seeded one.
 *
 * The key-management specs submit the add-key form repeatedly and the devcontainer
 * seed ships no cryptokeys at all, so without a prune every run leaves more keys
 * behind than the last. Capture the ids up front, remove whatever the run added.
 */

import { expect } from '@playwright/test';
import { errorMessages } from './zones.js';

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

  await addDnssecKey(page, zoneId);
}

/**
 * Submit the add-key form and wait for the redirect to the key list.
 * Throws when the submit does not redirect or the key count did not grow by one.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @param {{keyType?: string}} [opts] - key_type option value, e.g. 'ksk' or 'zsk'
 * @returns {Promise<string>} Id of the new key
 */
export async function addDnssecKey(page, zoneId, { keyType } = {}) {
  const before = await listDnssecKeyIds(page, zoneId);

  await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
  if (keyType) {
    await page.locator('select[name="key_type"]').selectOption(keyType);
  }
  const submit = page.locator('button[type="submit"], input[type="submit"]').first();
  try {
    await Promise.all([
      page.waitForURL(url => url.pathname.endsWith(`/zones/${zoneId}/dnssec`), { timeout: 30000 }),
      submit.click(),
    ]);
  } catch {
    throw new Error(`adding a DNSSEC key to zone ${zoneId} was refused: ${await errorMessages(page) || 'no redirect to the key list'}`);
  }

  const added = (await listDnssecKeyIds(page, zoneId)).filter(id => !before.includes(id));
  if (added.length !== 1) {
    throw new Error(`adding a DNSSEC key to zone ${zoneId} created ${added.length} keys, expected 1: ${await errorMessages(page)}`);
  }
  return added[0];
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
    await page.waitForURL(url => url.pathname.endsWith(`/zones/${zoneId}/dnssec`), { timeout: 15000 });
    removed++;
  }

  return removed;
}

/**
 * Count the Activate / Deactivate toggle links on the key list.
 *
 * @param {import('@playwright/test').Page} page - already on /zones/{id}/dnssec
 * @returns {Promise<{activate: number, deactivate: number}>}
 */
async function countToggleLinks(page) {
  return {
    // Icon classes are not translated: play-circle = inactive key, pause-circle = active key
    activate: await page.locator('table a[href*="/dnssec/keys/"][href$="/edit"] i.bi-play-circle').count(),
    deactivate: await page.locator('table a[href*="/dnssec/keys/"][href$="/edit"] i.bi-pause-circle').count(),
  };
}

/**
 * Flip one key between active and inactive and assert the flip happened.
 * The redirect back to the key list also happens on error, so the Activate and
 * Deactivate link counts are compared before and after.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @param {string|number} keyId
 * @returns {Promise<'activated'|'deactivated'>}
 */
export async function submitKeyToggle(page, zoneId, keyId) {
  await page.goto(`/zones/${zoneId}/dnssec`);
  const before = await countToggleLinks(page);

  await page.goto(`/zones/${zoneId}/dnssec/keys/${keyId}/edit`);
  const form = page.locator('form[action$="/toggle"]');
  if (await form.count() === 0) {
    throw new Error(`key ${keyId} of zone ${zoneId} has no toggle form`);
  }
  await form.locator('button[type="submit"]').click();
  await page.waitForURL(url => url.pathname.endsWith(`/zones/${zoneId}/dnssec`), { timeout: 15000 });

  const after = await countToggleLinks(page);
  if (after.activate === before.activate + 1 && after.deactivate === before.deactivate - 1) {
    return 'deactivated';
  }
  if (after.activate === before.activate - 1 && after.deactivate === before.deactivate + 1) {
    return 'activated';
  }
  throw new Error(
    `toggling key ${keyId} of zone ${zoneId} changed nothing: ` +
    `before ${JSON.stringify(before)}, after ${JSON.stringify(after)}`
  );
}

/**
 * Leave a zone signed with at least one ACTIVE key.
 *
 * The add-key form creates inactive keys and PowerDNS counts a zone as signed
 * only with an active one, so this uses the "Sign zone" button on the edit page
 * and falls back to activating an existing key. The zone needs an apex NS
 * record (createTempZone adds one) or the app refuses to sign.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} zoneId
 * @returns {Promise<void>}
 */
export async function ensureZoneSigned(page, zoneId) {
  const manageLink = page.locator(`a[href$="/zones/${zoneId}/dnssec"]`);
  const signButton = page.locator('button[name="sign_zone"]');

  await page.goto(`/zones/${zoneId}/edit`);

  if (await signButton.count() > 0) {
    // EditController renders the edit page in the POST response (no redirect)
    const [response] = await Promise.all([
      page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(`/zones/${zoneId}/edit`), { timeout: 30000 }),
      signButton.click(),
    ]);
    if (!response.ok()) {
      throw new Error(`signing zone ${zoneId} returned HTTP ${response.status()}: ${await errorMessages(page)}`);
    }
    try {
      await expect(manageLink).toBeVisible({ timeout: 15000 });
    } catch {
      throw new Error(`zone ${zoneId} is not signed after signing: ${await errorMessages(page) || 'DNSSEC disabled, no apex NS, or signing refused'}`);
    }
  }

  if (await manageLink.count() === 0) {
    throw new Error(`zone ${zoneId} is not signed: ${await errorMessages(page) || 'DNSSEC disabled, no apex NS, or signing refused'}`);
  }

  await page.goto(`/zones/${zoneId}/dnssec`);
  if ((await countToggleLinks(page)).deactivate === 0) {
    // Keys exist but none is active; activate the first
    if ((await listDnssecKeyIds(page, zoneId)).length === 0) {
      await addDnssecKey(page, zoneId);
    }
    const [keyId] = await listDnssecKeyIds(page, zoneId);
    if (!keyId) {
      throw new Error(`zone ${zoneId} has no DNSSEC key to activate`);
    }
    await submitKeyToggle(page, zoneId, keyId);
  }

  await page.goto(`/zones/${zoneId}/edit`);
  if (await manageLink.count() === 0) {
    throw new Error(`zone ${zoneId} does not show as signed after ensureZoneSigned`);
  }
}
