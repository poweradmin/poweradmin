/**
 * Zone File Export Module E2E Tests
 *
 * Tests for the BIND zone file export functionality.
 * Requires zone_import_export module to be enabled.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addRecord } from '../../helpers/zones.js';

test.describe('Zone File Export Module', () => {
  test('should show Zone File option in Export dropdown when module is enabled', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/edit`);

    await page.locator('button.dropdown-toggle:has-text("Export")').click();

    const zoneFileLink = page.locator('.dropdown-menu a:has-text("Zone File")');
    await expect(zoneFileLink).toBeVisible();
    await expect(zoneFileLink).toHaveAttribute('href', new RegExp(`/zones/${zoneId}/export/zonefile$`));
  });

  test('should download zone file', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    const downloadPromise = page.waitForEvent('download');

    // Navigating to a download URL aborts the navigation once the transfer starts,
    // so page.goto() rejects with "Download is starting" - the download still fires.
    await page.goto(`/zones/${zoneId}/export/zonefile`).catch(() => {});

    const download = await downloadPromise;

    expect(download.suggestedFilename()).toMatch(/\.zone$/);
  });

  test('should contain valid BIND zone file content', async ({ page, request, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;
    await addRecord(page, zoneId, { name: 'www', type: 'A', content: '192.0.2.77', ttl: 3600 });

    // Get cookies from authenticated session
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');

    const response = await request.get(`/zones/${zoneId}/export/zonefile`, {
      headers: { Cookie: cookieHeader }
    });

    expect(response.status()).toBe(200);
    const body = await response.text();

    // The export opens with $ORIGIN/$TTL but prints every record with its absolute name, tab-separated
    expect(body).toMatch(new RegExp(`^${tempZone.name.replace(/\./g, '\\.')}\\.\\s+\\d+\\s+IN\\s+SOA\\s`, 'm'));
    expect(body).toMatch(new RegExp(`^www\\.${tempZone.name.replace(/\./g, '\\.')}\\.\\s+3600\\s+IN\\s+A\\s+192\\.0\\.2\\.77$`, 'm'));
  });

  test('should deny zone file export for non-authenticated users', async ({ page }) => {
    await page.goto('/zones/1/export/zonefile');
    await page.waitForLoadState('networkidle');

    const url = page.url();
    const bodyText = await page.locator('body').textContent();
    const denied = url.includes('login') ||
                   bodyText.toLowerCase().includes('permission') ||
                   bodyText.toLowerCase().includes('denied') ||
                   bodyText.toLowerCase().includes('login') ||
                   bodyText.toLowerCase().includes('not found');
    expect(denied).toBeTruthy();
  });
});
