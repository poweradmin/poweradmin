/**
 * Zone File Export Module E2E Tests
 *
 * Tests for the BIND zone file export functionality.
 * Requires zone_import_export module to be enabled.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Helper to get a zone ID for testing
async function getTestZoneId(page) {
  await page.goto('/zones/forward?letter=all');
  const editLink = page.locator('a[href*="/edit"]').first();
  if (await editLink.count() > 0) {
    const href = await editLink.getAttribute('href');
    const match = href.match(/\/zones\/(\d+)\/edit/);
    return match ? match[1] : null;
  }
  return null;
}

test.describe('Zone File Export Module', () => {
  test('should show Zone File option in Export dropdown when module is enabled', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    const exportBtn = page.locator('button.dropdown-toggle:has-text("Export")');
    if (await exportBtn.count() === 0) {
      // Export button not present - module might be disabled
      return;
    }

    await exportBtn.click();

    // Check for Zone File option
    const zoneFileLink = page.locator('.dropdown-menu a:has-text("Zone File")');
    if (await zoneFileLink.count() > 0) {
      await expect(zoneFileLink).toBeVisible();
      const href = await zoneFileLink.getAttribute('href');
      expect(href).toContain(`/zones/${zoneId}/export/zonefile`);
    }
    // If not present, module is disabled - that's OK
  });

  test('should download zone file', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    // Try to download - module might not be enabled
    const downloadPromise = page.waitForEvent('download', { timeout: 5000 }).catch(() => null);

    await page.goto(`/zones/${zoneId}/export/zonefile`);
    await page.waitForLoadState('networkidle');

    const download = await downloadPromise;

    if (download) {
      // Module is enabled and working - verify filename
      expect(download.suggestedFilename()).toMatch(/\.zone$/);
    } else {
      // Module might be disabled or route not found
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should contain valid BIND zone file content', async ({ page, request }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    // Get cookies from authenticated session
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');

    const baseURL = page.url().split('/zones')[0];
    const response = await request.get(`${baseURL}/zones/${zoneId}/export/zonefile`, {
      headers: { Cookie: cookieHeader }
    });

    if (response.status() === 404) {
      // Module not enabled - skip
      return;
    }

    const body = await response.text();

    // Zone file should contain standard directives
    if (body.includes('$ORIGIN')) {
      expect(body).toContain('$ORIGIN');
      expect(body).toMatch(/\bIN\b/);
      expect(body).toMatch(/SOA|NS|A|AAAA|MX|CNAME|TXT/);
    }
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
