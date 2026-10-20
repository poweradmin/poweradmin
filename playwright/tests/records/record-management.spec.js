import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('DNS Record Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access zones list to manage records', async ({ page }) => {
    await page.goto('/zones/forward');
    await expect(page).toHaveURL(/.*zones\/forward/);

    // The standard test data provides forward zones, so the table must be there
    await expect(page.locator('main table, table').first()).toBeVisible();
  });

  test('should open the record list of a zone from the zone list', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    const editLinks = page.locator('table tbody tr a[href*="/edit"]');
    await expect(editLinks.first()).toBeVisible();
    await editLinks.first().click();
    await page.waitForLoadState('networkidle');

    // Should be on zone edit/records page
    await expect(page).toHaveURL(/zones\/\d+\/edit/);
  });

  test('should validate record form fields', async ({ page }) => {
    const zoneId = await getTestZoneId(page, 'admin');
    expect(zoneId, 'admin-zone.example.com must exist in the standard test data').toBeTruthy();
    await page.goto(`/zones/${zoneId}/records/add`, { waitUntil: 'networkidle' });

    // Should have record name field (use first matching text input)
    await expect(page.locator('input.name-field, input[name*="[name]"]').first()).toBeVisible();

    // Should have record type selector
    await expect(page.locator('select[name*="type"], select.record-type-select').first()).toBeVisible();

    // Should have record content/value field
    await expect(page.locator('input.record-content, input[name*="[content]"]').first()).toBeVisible();
  });

  test('should handle record type changes', async ({ page }) => {
    const zoneId = await getTestZoneId(page, 'admin');
    expect(zoneId, 'admin-zone.example.com must exist in the standard test data').toBeTruthy();
    await page.goto(`/zones/${zoneId}/records/add`, { waitUntil: 'networkidle' });

    const typeSelect = page.locator('select[name="records[0][type]"]');
    await expect(typeSelect).toBeVisible();

    await typeSelect.selectOption('A');
    await expect(typeSelect).toHaveValue('A');

    await typeSelect.selectOption('MX');
    await expect(typeSelect).toHaveValue('MX');
  });

  test('should validate required fields for new record', async ({ page }) => {
    const zoneId = await getTestZoneId(page, 'admin');
    expect(zoneId, 'admin-zone.example.com must exist in the standard test data').toBeTruthy();
    await page.goto(`/zones/${zoneId}/records/add`, { waitUntil: 'networkidle' });

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Content is required, so the browser keeps the form on the page
    await expect(page).toHaveURL(/.*records\/add/);
    await expect(page.locator('form:has(input[name="records[0][content]"])')).toHaveClass(/was-validated/);
  });
});
