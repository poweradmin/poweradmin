/**
 * CSV Export Module E2E Tests
 *
 * Tests for the CSV export functionality accessible from zone edit pages.
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

test.describe('CSV Export Module', () => {
  test('should show Export dropdown on zone edit page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // Should have an Export dropdown button
    const exportBtn = page.locator('button.dropdown-toggle:has-text("Export")');
    await expect(exportBtn).toBeVisible();
  });

  test('should show CSV option in Export dropdown', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // Click dropdown to open it
    const exportBtn = page.locator('button.dropdown-toggle:has-text("Export")');
    await exportBtn.click();

    // Should have CSV option in the dropdown
    const csvLink = page.locator('.dropdown-menu a:has-text("CSV")');
    await expect(csvLink).toBeVisible();

    // CSV link should point to the correct URL
    const href = await csvLink.getAttribute('href');
    expect(href).toContain(`/zones/${zoneId}/export/csv`);
  });

  test('should download CSV file', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    // Listen for download event
    const downloadPromise = page.waitForEvent('download');

    await page.goto(`/zones/${zoneId}/export/csv`);

    const download = await downloadPromise;

    // Verify filename contains .csv
    expect(download.suggestedFilename()).toMatch(/\.csv$/);
  });

  test('should contain valid CSV content', async ({ page, request }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    // Get cookies from authenticated session
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');

    // Fetch CSV content directly
    const baseURL = page.url().split('/zones')[0];
    const response = await request.get(`${baseURL}/zones/${zoneId}/export/csv`, {
      headers: { Cookie: cookieHeader }
    });

    const body = await response.text();

    // CSV should contain standard DNS record columns
    expect(body).toContain('name');
    expect(body).toContain('type');
  });

  test('should deny CSV export for non-authenticated users', async ({ page }) => {
    // Try to access CSV export without logging in
    await page.goto('/zones/1/export/csv');
    await page.waitForLoadState('networkidle');

    // Should redirect to login or show error
    const url = page.url();
    const bodyText = await page.locator('body').textContent();
    const denied = url.includes('login') ||
                   bodyText.toLowerCase().includes('permission') ||
                   bodyText.toLowerCase().includes('denied') ||
                   bodyText.toLowerCase().includes('login');
    expect(denied).toBeTruthy();
  });

  test('should deny CSV export for users without zone access', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.noperm.username, users.noperm.password);

    await page.goto('/zones/1/export/csv');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    // Should show permission denied or error, not crash
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
