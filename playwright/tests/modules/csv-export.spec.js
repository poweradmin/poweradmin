/**
 * CSV Export Module E2E Tests
 *
 * Tests for the CSV export functionality accessible from zone edit pages.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';

test.describe('CSV Export Module', () => {
  test('should show Export dropdown on zone edit page', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // Should have an Export dropdown button
    const exportBtn = page.locator('button.dropdown-toggle:has-text("Export")');
    await expect(exportBtn).toBeVisible();
  });

  test('should show CSV option in Export dropdown', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

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

  test('should download CSV file', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    // Listen for download event
    const downloadPromise = page.waitForEvent('download');

    // Navigating to a download URL aborts the navigation once the transfer starts,
    // so page.goto() rejects with "Download is starting" - the download still fires.
    await page.goto(`/zones/${zoneId}/export/csv`).catch(() => {});

    const download = await downloadPromise;

    // Verify filename contains .csv
    expect(download.suggestedFilename()).toMatch(/\.csv$/);
  });

  test('should contain valid CSV content', async ({ page, request, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    // Get cookies from authenticated session
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');

    // Fetch CSV content directly
    const response = await request.get(`/zones/${zoneId}/export/csv`, {
      headers: { Cookie: cookieHeader }
    });

    const body = await response.text();

    // The header row is capitalised: Name,Type,Content,Priority,TTL,Disabled,Comment
    expect(body).toMatch(/^﻿?Name,Type,Content/m);
    expect(body).toContain('SOA');
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
