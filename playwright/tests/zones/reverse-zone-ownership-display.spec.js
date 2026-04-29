/**
 * Reverse Zone Ownership Display Tests (Issue #1180)
 *
 * Regression coverage for:
 *   - Phantom user icon appearing for reverse zones with no user owner
 *   - Same user listed more than once on the ownership page
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Reverse Zone Ownership Display (Issue #1180)', () => {
  test('reverse zone row never shows a user icon without an owner name', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/reverse');

    const rows = page.locator('tbody tr');
    const count = await rows.count();
    test.skip(count === 0, 'No reverse zones in this environment');

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
    await page.goto('/zones/reverse');

    const editLink = page.locator('tbody tr a[href*="/edit"]').first();
    test.skip(await editLink.count() === 0, 'No reverse zones in this environment');

    const editHref = await editLink.getAttribute('href');
    const match = editHref.match(/\/zones\/(\d+)/);
    test.skip(!match, 'Could not derive zone id');
    await page.goto(`/zones/${match[1]}/ownership`);

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
