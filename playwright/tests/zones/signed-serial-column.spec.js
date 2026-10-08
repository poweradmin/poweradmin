/**
 * Signed Serial Display Tests (Issue #1378)
 *
 * The serial served by PowerDNS (SOA-EDIT applied) is shown in the zone list
 * behind the display_signed_serial_in_zone_list setting (API backend only),
 * and on the edit page for signed zones. The list test skips on instances
 * where the setting is off. Both tests run on throwaway zones.
 */

import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getColumnIndex, openZoneListPageFor } from '../../helpers/zones.js';
import { ensureZoneSigned } from '../../helpers/dnssec.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Signed Serial Display (Issue #1378)', () => {
  const zone = useFileZone('signedserial');

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await ensureZoneSigned(page, zone.id);
    } finally {
      await page.close();
    }
  });

  // The zone list row of one zone: its lock state and signed-serial cell
  async function listRowState(page, zoneName) {
    const found = await openZoneListPageFor(page, zoneName);
    expect(found, `${zoneName} must be listed`).toBe(true);
    const signedIdx = await getColumnIndex(page, 'Signed serial');
    if (signedIdx === -1) {
      return null;
    }
    return page.evaluate(({ idx, name }) => {
      const row = Array.from(document.querySelectorAll('tbody tr')).find(r => r.innerText.includes(name));
      return {
        signedSerial: row.querySelectorAll('td')[idx]?.innerText.trim() ?? '',
        secured: !!row.querySelector('i.bi-lock-fill'),
      };
    }, { idx: signedIdx, name: zoneName });
  }

  test('zone list shows signed serial only for signed zones', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    const signed = await listRowState(page, zone.name);
    test.skip(signed === null, 'display_signed_serial_in_zone_list not enabled on this instance');
    expect(signed.secured, 'the file zone was signed in beforeAll').toBe(true);
    expect(signed.signedSerial, 'a signed zone must show a numeric signed serial').toMatch(/^\d+$/);

    const unsigned = await listRowState(page, tempZone.name);
    expect(unsigned.secured, 'a fresh zone is not signed').toBe(false);
    expect(unsigned.signedSerial, 'an unsigned zone must not show a signed serial').toBe('');
  });

  test('edit page shows signed serial for a signed zone', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto(`/zones/${zone.id}/edit`);

    // Signed serial sits in the Zone Configuration card, which renders expanded
    await expect(page.locator('p:has-text("Signed serial:")')).toBeVisible();
    await expect(page.locator('p:has-text("Signed serial:") code')).toHaveText(/^\d+$/);
  });
});
