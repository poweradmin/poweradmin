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
import users from '../../fixtures/users.json' assert { type: 'json' };

const API_MODE_PORTS = ['8083', '8084', '8085'];

const isApiModeInstance = (baseURL) =>
  API_MODE_PORTS.some((port) => (baseURL || '').includes(`:${port}`));

test.describe('Zone list in API backend mode', () => {
  test.beforeEach(async ({ baseURL }) => {
    test.skip(!isApiModeInstance(baseURL), 'requires an API-backend instance (ports 8083-8085)');
  });

  test('records column is rendered but not sortable', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const recordsHeader = page.locator('th', { hasText: /^\s*Records\s*$/ });
    await expect(recordsHeader).toHaveCount(1);

    // Sorting on a per-page value would only order the rows already on screen
    await expect(page.locator('th a[href*="zone_sort_by=count_records"]')).toHaveCount(0);
  });

  test('records column shows a count for each zone', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const firstRow = page.locator('table tbody tr').first();
    await expect(firstRow).toBeVisible();

    // A zone that exists in PowerDNS always has at least an SOA record, so a
    // blank or zero count here means the per-page fetch did not run
    const counts = await page.locator('table tbody tr td:nth-child(4)').allTextContents();
    expect(counts.length).toBeGreaterThan(0);
    for (const count of counts) {
      expect(Number.parseInt(count.trim(), 10)).toBeGreaterThan(0);
    }
  });

  test('name and type columns stay sortable', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('th a[href*="zone_sort_by=name"]')).not.toHaveCount(0);
    await expect(page.locator('th a[href*="zone_sort_by=type"]')).not.toHaveCount(0);
  });

  test('a stale count_records sort in the URL falls back instead of erroring', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // A session carried over from SQL mode can still ask for this column
    const response = await page.goto('/zones/forward?letter=all&zone_sort_by=count_records');
    expect(response.status()).toBe(200);
    await expect(page.locator('table tbody tr').first()).toBeVisible();
  });

  test('reverse zone list applies the same rules', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/reverse');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('th a[href*="zone_sort_by=count_records"]')).toHaveCount(0);
  });
});
