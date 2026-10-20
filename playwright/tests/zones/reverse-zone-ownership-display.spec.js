/**
 * Reverse Zone Ownership Display Tests (Issue #1180)
 *
 * Regression coverage for:
 *   - Phantom user icon appearing for reverse zones with no user owner
 *   - Same user listed more than once on the ownership page
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('Reverse Zone Ownership Display (Issue #1180)', () => {
  test('reverse zone row never shows a user icon without an owner name', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/reverse');

    const rows = page.locator('tbody tr');
    const count = await rows.count();
    // 2.0.192.in-addr.arpa and 8.b.d.0.1.0.0.2.ip6.arpa are seeded
    expect(count, 'seeded reverse zones must be listed').toBeGreaterThan(0);

    for (let i = 0; i < count; i++) {
      const ownerCell = rows.nth(i).locator('td').nth(4);
      const cellText = (await ownerCell.innerText()).trim();
      const personIcons = await ownerCell.locator('i.bi-person').count();

      if (cellText === '') {
        expect(personIcons, 'cell with no owner text must not show person icon').toBe(0);
      }
    }
  });

  test('ownership page does not show duplicate user owners', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page, 'reverseIPv4');
    expect(zoneId, '2.0.192.in-addr.arpa must be seeded').toBeTruthy();
    await page.goto(`/zones/${zoneId}/ownership`);

    const ownerNames = await page
      .locator('.card')
      .filter({ hasText: 'User Owner' })
      .locator('ul li')
      .allInnerTexts();

    const seen = new Set();
    for (const raw of ownerNames) {
      const name = raw.trim().split('\n')[0];
      if (!name) continue;
      expect(seen.has(name), `owner "${name}" listed more than once`).toBe(false);
      seen.add(name);
    }
  });
});
