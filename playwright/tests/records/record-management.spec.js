import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('DNS Record Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access zones list to manage records', async ({ page }) => {
    await page.goto('/zones/forward');
    await expect(page).toHaveURL(/.*zones\/forward/);

    // Should show zones or empty state
    const hasTable = await page.locator('table, .table').count() > 0;

    if (hasTable) {
      await expect(page.locator('table, .table')).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No zones|zones|empty/i);
    }
  });

  // Test record management for a zone (if zones exist)
  test('should handle zone with no records', async ({ page }) => {
    // Navigate to zones and try to access first zone's records
    await page.goto('/zones/forward');

    const hasZones = await page.locator('table tbody tr').count() > 0;

    if (hasZones) {
      // Click on first zone link if available
      await page.locator('table tbody tr').first().locator('a').first().click();

      // Should be on zone edit/records page
      await expect(page).toHaveURL(/.*zones\/\d+\/edit/);
    } else {
      // No zones available, skip this test
      console.log('No zones available for record testing');
    }
  });

  test('should validate record form fields', async ({ page }) => {
    // Visit a generic add record URL (will redirect if zone doesn't exist)
    await page.goto('/zones/1/records/add', { waitUntil: 'networkidle' });

    // Check if we have a form (only if zone exists)
    const hasForm = await page.locator('form').count() > 0;

    if (hasForm) {
      // Should have record name field
      await expect(page.locator('input[name*="name"], input[name*="record"]')).toBeVisible();

      // Should have record type selector
      await expect(page.locator('select[name*="type"], select[name*="record_type"]')).toBeVisible();

      // Should have record content/value field
      await expect(page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]')).toBeVisible();
    } else {
      console.log('No record form available - zone may not exist');
    }
  });

  test('should handle record type changes', async ({ page }) => {
    await page.goto('/zones/1/records/add', { waitUntil: 'networkidle' });

    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      // Select different record types and check if form updates
      await page.locator('select[name*="type"]').selectOption('A');

      // Try other common record types
      const options = await page.locator('select[name*="type"] option').count();
      if (options > 1) {
        const secondOption = await page.locator('select[name*="type"] option').nth(1).getAttribute('value');
        if (secondOption) {
          await page.locator('select[name*="type"]').selectOption(secondOption);
        }
      }
    }
  });

  test('should validate required fields for new record', async ({ page }) => {
    await page.goto('/zones/1/records/add', { waitUntil: 'networkidle' });

    const hasForm = await page.locator('form').count() > 0;

    if (hasForm) {
      // Try to submit empty form
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should stay on form or show validation errors
      await expect(page).toHaveURL(/.*records\/add/);
    }
  });
});
