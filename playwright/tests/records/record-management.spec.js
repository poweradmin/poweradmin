import { test, expect } from '../../fixtures/test-fixtures.js';
import { findAnyZoneId } from '../../helpers/zones.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNS Record Management', () => {
  test('should access zones list to manage records', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones');
    await expect(page).toHaveURL(/page=list_forward_zones/);

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
  test('should handle zone with no records', async ({ adminPage: page }) => {
    // Use findAnyZoneId helper to find a zone
    const zone = await findAnyZoneId(page);

    if (zone && zone.id) {
      // Navigate directly to zone edit page
      await page.goto(`/index.php?page=edit&id=${zone.id}`);

      // Verify page loads without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should validate record form fields', async ({ adminPage: page }) => {
    // Visit a generic add record URL (will redirect if zone doesn't exist)
    await page.goto('/index.php?page=add_record&id=1', { waitUntil: 'networkidle' });

    // Check if we have a form (only if zone exists)
    const hasForm = await page.locator('form').count() > 0;

    if (hasForm) {
      // Should have record name field - use first() to avoid strict mode violation
      await expect(page.locator('input[name*="name"], input[name*="record"]').first()).toBeVisible();

      // Should have record type selector - use first() to avoid strict mode violation
      await expect(page.locator('select[name*="type"], select[name*="record_type"]').first()).toBeVisible();

      // Should have record content/value field - use first() to avoid strict mode violation
      await expect(page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first()).toBeVisible();
    } else {
      console.log('No record form available - zone may not exist');
    }
  });

  test('should handle record type changes', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_record&id=1', { waitUntil: 'networkidle' });

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

  test('should validate required fields for new record', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_record&id=1', { waitUntil: 'networkidle' });

    const hasForm = await page.locator('form').count() > 0;

    if (hasForm) {
      // Try to submit empty form
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should stay on form or show validation errors
      await expect(page).toHaveURL(/page=add_record/);
    }
  });
});
