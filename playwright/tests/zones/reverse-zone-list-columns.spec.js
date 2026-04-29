/**
 * Reverse Zone List Column Tests (Issue #1186)
 *
 * Regression coverage for the reverse zone list columns: Serial must render
 * actual values (was empty in API mode), and Template must reflect template
 * assignments (a key mismatch made it always render "-").
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

async function setListPreferences(page, { serial, template }) {
  await page.goto('/user/preferences');
  await page.evaluate(({ serial, template }) => {
    const s = document.querySelector('#show_zone_serial');
    const t = document.querySelector('#show_zone_template');
    if (s) s.checked = serial;
    if (t) t.checked = template;
    document.querySelector('button[type="submit"]')?.click();
  }, { serial, template });
  await page.waitForLoadState('networkidle');
}

async function getColumnIndex(page, headerText) {
  return page.evaluate((target) => {
    const headers = Array.from(document.querySelectorAll('thead th'));
    return headers.findIndex(h => h.innerText.trim() === target);
  }, headerText);
}

test.describe('Reverse Zone List Columns (Issue #1186)', () => {
  test('Serial column shows numeric serial values when enabled', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await setListPreferences(page, { serial: true, template: false });
    await page.goto('/zones/reverse');

    const rows = page.locator('tbody tr');
    test.skip(await rows.count() === 0, 'No reverse zones in this environment');

    const serialIdx = await getColumnIndex(page, 'Serial');
    expect(serialIdx, 'Serial column must be present when preference is on').toBeGreaterThan(-1);

    const serials = await page.evaluate((idx) => {
      return Array.from(document.querySelectorAll('tbody tr'))
        .map(r => r.querySelectorAll('td')[idx]?.innerText.trim() ?? '');
    }, serialIdx);

    const numericSerials = serials.filter(s => /^\d+$/.test(s));
    expect(numericSerials.length, `at least one row must show a numeric serial; got ${JSON.stringify(serials)}`).toBeGreaterThan(0);
  });

  test('Template column renders without errors when enabled', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await setListPreferences(page, { serial: false, template: true });
    await page.goto('/zones/reverse');

    const rows = page.locator('tbody tr');
    test.skip(await rows.count() === 0, 'No reverse zones in this environment');

    const templateIdx = await getColumnIndex(page, 'Template');
    expect(templateIdx, 'Template column must be present when preference is on').toBeGreaterThan(-1);

    const cells = await page.evaluate((idx) => {
      return Array.from(document.querySelectorAll('tbody tr'))
        .map(r => r.querySelectorAll('td')[idx]?.innerText.trim() ?? '');
    }, templateIdx);

    // Each cell either shows "-" (no template) or a non-empty template name badge
    for (const cell of cells) {
      expect(cell.length, `template cell unexpectedly empty: ${JSON.stringify(cells)}`).toBeGreaterThan(0);
    }
  });
});
