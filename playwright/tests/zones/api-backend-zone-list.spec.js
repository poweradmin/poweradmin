/**
 * API Backend Zone List Tests
 *
 * Covers zone list behaviour that only applies when PowerDNS is the data source
 * (dns.backend = 'api'). Record counts are resolved one API call per visible
 * row, so the Records column is populated but not sortable - see #1387.
 *
 * Run against an API-mode instance:
 *   BASE_URL=http://localhost:8083 npx playwright test api-backend-zone-list --workers=1
 * (8083 MySQL+API, 8084 PostgreSQL+API, 8085 SQLite+API)
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getColumnIndex } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

const API_MODE_PORTS = ['8083', '8084', '8085'];

const isApiModeInstance = (baseURL) =>
  API_MODE_PORTS.some((port) => (baseURL || '').includes(`:${port}`));

test.describe('Zone list in API backend mode', () => {
  test.beforeEach(async ({ page, baseURL }) => {
    test.skip(!isApiModeInstance(baseURL), 'requires an API-backend instance (ports 8083-8085)');
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('records column is rendered but not sortable', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const recordsIdx = await getColumnIndex(page, 'Records');
    test.skip(recordsIdx === -1, 'show_zone_record_count not enabled on this instance');

    // Sorting on a per-page value would only order the rows already on screen
    await expect(page.locator('th a[href*="zone_sort_by=count_records"]')).toHaveCount(0);
  });

  test('records column shows a count for each zone', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const rows = page.locator('tbody tr');
    test.skip(await rows.count() === 0, 'No forward zones in this environment');

    const recordsIdx = await getColumnIndex(page, 'Records');
    test.skip(recordsIdx === -1, 'show_zone_record_count not enabled on this instance');

    const counts = await page.evaluate((idx) => {
      return Array.from(document.querySelectorAll('tbody tr'))
        .map(r => r.querySelectorAll('td')[idx]?.innerText.trim() ?? '');
    }, recordsIdx);

    // Every row carries a number. Zero is legitimate - a secondary zone that
    // has not transferred yet holds no records - so the signal that the
    // per-page fetch actually ran is that some row is non-zero.
    expect(counts.length).toBeGreaterThan(0);
    for (const count of counts) {
      expect(count).toMatch(/^\d+$/);
    }
    expect(counts.some(count => Number.parseInt(count, 10) > 0)).toBe(true);
  });

  test('name and type columns stay sortable', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('th a[href*="zone_sort_by=name"]')).not.toHaveCount(0);
    await expect(page.locator('th a[href*="zone_sort_by=type"]')).not.toHaveCount(0);
  });

  test('a stale count_records sort in the URL falls back instead of erroring', async ({ page }) => {
    // A session carried over from SQL mode can still ask for this column
    const response = await page.goto('/zones/forward?letter=all&zone_sort_by=count_records');
    expect(response.status()).toBe(200);
    await expect(page.locator('tbody tr').first()).toBeVisible();
  });

  test('reverse zone list applies the same rules', async ({ page }) => {
    await page.goto('/zones/reverse');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('th a[href*="zone_sort_by=count_records"]')).toHaveCount(0);
  });
});
