/**
 * Signed Serial Display Tests (Issue #1378)
 *
 * The serial served by PowerDNS (SOA-EDIT applied) is shown in the zone list
 * behind the display_signed_serial_in_zone_list setting (API backend only),
 * and on the edit page for signed zones. Tests skip on instances where the
 * setting or DNSSEC data is not available.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getColumnIndex } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Signed Serial Display (Issue #1378)', () => {
  test('zone list shows signed serial only for signed zones', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const rows = page.locator('tbody tr');
    test.skip(await rows.count() === 0, 'No forward zones in this environment');

    const signedIdx = await getColumnIndex(page, 'Signed serial');
    test.skip(signedIdx === -1, 'display_signed_serial_in_zone_list not enabled on this instance');

    // Pair each row's signed-serial cell with its DNSSEC lock state
    const rowStates = await page.evaluate((idx) => {
      return Array.from(document.querySelectorAll('tbody tr')).map(r => ({
        signedSerial: r.querySelectorAll('td')[idx]?.innerText.trim() ?? '',
        secured: !!r.querySelector('i.bi-lock-fill'),
      }));
    }, signedIdx);

    const securedRows = rowStates.filter(r => r.secured);
    test.skip(securedRows.length === 0, 'No signed zones in this environment');

    for (const row of securedRows) {
      expect(row.signedSerial, 'signed zones must show a numeric signed serial').toMatch(/^\d+$/);
    }
    for (const row of rowStates.filter(r => !r.secured)) {
      expect(row.signedSerial, 'unsigned zones must not show a signed serial').toBe('');
    }
  });

  test('edit page shows signed serial for a signed zone', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    // Find a signed zone's edit link via its row lock icon
    const editHref = await page.evaluate(() => {
      const row = Array.from(document.querySelectorAll('tbody tr'))
        .find(r => r.querySelector('i.bi-lock-fill'));
      return row?.querySelector('a[href*="/edit"]')?.getAttribute('href') ?? null;
    });
    test.skip(!editHref, 'No signed zones in this environment');

    await page.goto(editHref);
    await page.waitForLoadState('networkidle');

    // Signed serial sits inside the collapsed Zone Configuration card
    await page.locator('[data-bs-target="#zone-config-body"]').click();
    await expect(page.locator('#zone-config-body')).toContainText('Signed serial:');
    await expect(page.locator('#zone-config-body p:has-text("Signed serial:") code')).toHaveText(/^\d+$/);
  });
});
