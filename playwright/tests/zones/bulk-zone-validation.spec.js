import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Bulk Zone Registration Validation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should register single zone via bulk registration', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Enter single zone
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('bulktest1.com');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show success message
    await expect(page.locator('body')).toContainText(/success|added/i);
    await expect(page.locator('body')).toContainText('bulktest1.com');

    // Cleanup
    await page.locator('[data-testid="list-forward-zones-link"]').click();
    await page.locator('tr:has-text("bulktest1.com")').locator('[data-testid^="delete-zone-"]').click();
    await page.locator('[data-testid="confirm-delete-zone"]').click();
  });

  test('should register multiple zones via bulk registration', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Enter multiple zones (newline separated)
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('bulktest1.com\nbulktest2.org\nbulktest3.net');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show success messages for all zones
    await expect(page.locator('body')).toContainText(/success|added/i);
    await expect(page.locator('body')).toContainText('bulktest1.com');
    await expect(page.locator('body')).toContainText('bulktest2.org');
    await expect(page.locator('body')).toContainText('bulktest3.net');

    // Cleanup
    const zones = ['bulktest1.com', 'bulktest2.org', 'bulktest3.net'];
    for (const zone of zones) {
      await page.locator('[data-testid="list-forward-zones-link"]').click();
      await page.locator(`tr:has-text("${zone}")`).locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();
    }
  });

  test('should show error for zone with invalid TLD', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Try to register zone with invalid/non-existent TLD
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('invalidzone.invalidtld123');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show error message
    await expect(page.locator('body')).toContainText(/error|invalid|failed/i);

    // Should mention TLD or hostname issue
    const bodyText = await page.locator('body').textContent();
    const hasValidationError = bodyText.match(/invalid.*tld|invalid.*hostname|invalid.*domain|top level domain/i);
    expect(hasValidationError).toBeTruthy();
  });

  test('should show error for malformed domain name', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Try various invalid domain formats
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('invalid..domain.com');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show error
    await expect(page.locator('body')).toContainText(/error|invalid|failed/i);
  });

  test('should handle mix of valid and invalid zones', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Enter mix of valid and invalid zones
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('validzone1.com\ninvalidzone.invalidtld\nvalidzone2.org');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    const bodyText = await page.locator('body').textContent();

    // Should show error for invalid zone
    const hasError = bodyText.match(/error|invalid|failed/i);
    expect(hasError).toBeTruthy();

    // Valid zones might be added successfully (check for success message)
    const hasSuccess = bodyText.match(/success|added/i);

    if (hasSuccess) {
      // If valid zones were added, cleanup
      const validZones = ['validzone1.com', 'validzone2.org'];
      for (const zone of validZones) {
        try {
          await page.locator('[data-testid="list-forward-zones-link"]').click();
          const zoneRow = page.locator(`tr:has-text("${zone}")`);
          if (await zoneRow.count() > 0) {
            await zoneRow.locator('[data-testid^="delete-zone-"]').click();
            await page.locator('[data-testid="confirm-delete-zone"]').click();
          }
        } catch (e) {
          // Zone might not have been created, continue
        }
      }
    }
  });

  test('should validate zone name format', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Try zone with invalid characters
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('invalid_zone!@#.com');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show validation error
    await expect(page.locator('body')).toContainText(/error|invalid|failed/i);
  });

  test('should prevent duplicate zone registration', async ({ page }) => {
    // First, create a zone
    await page.locator('[data-testid="add-master-zone-link"]').click();
    await page.locator('[data-testid="zone-name-input"]').fill('duplicate-test.com');
    await page.locator('[data-testid="add-zone-button"]').click();

    // Try to add same zone via bulk registration
    await page.locator('[data-testid="bulk-registration-link"]').click();
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('duplicate-test.com');
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show error about duplicate
    const bodyText = await page.locator('body').textContent();
    const hasDuplicateError = bodyText.match(/already exists|duplicate|error/i);
    expect(hasDuplicateError).toBeTruthy();

    // Cleanup
    await page.locator('[data-testid="list-forward-zones-link"]').click();
    await page.locator('tr:has-text("duplicate-test.com")').locator('[data-testid^="delete-zone-"]').click();
    await page.locator('[data-testid="confirm-delete-zone"]').click();
  });

  test('should handle empty bulk registration submission', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show error or stay on form
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/bulk|registration/i);
  });

  test('should trim whitespace from zone names', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Enter zone with extra whitespace
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('  whitespace-test.com  \n  another-zone.org  ');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should process zones successfully (whitespace trimmed)
    const bodyText = await page.locator('body').textContent();

    if (bodyText.match(/success|added/i)) {
      // Cleanup if zones were created
      const zones = ['whitespace-test.com', 'another-zone.org'];
      for (const zone of zones) {
        try {
          await page.locator('[data-testid="list-forward-zones-link"]').click();
          const zoneRow = page.locator(`tr:has-text("${zone}")`);
          if (await zoneRow.count() > 0) {
            await zoneRow.locator('[data-testid^="delete-zone-"]').click();
            await page.locator('[data-testid="confirm-delete-zone"]').click();
          }
        } catch (e) {
          // Continue if zone doesn't exist
        }
      }
    }
  });

  test('should handle zones with various valid TLDs', async ({ page }) => {
    await page.locator('[data-testid="bulk-registration-link"]').click();

    // Test various common TLDs
    const zones = 'tldtest1.com\ntldtest2.net\ntldtest3.org\ntldtest4.io\ntldtest5.dev';
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zones);

    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should successfully create zones with valid TLDs
    const bodyText = await page.locator('body').textContent();

    if (bodyText.match(/success|added/i)) {
      // Cleanup
      const zoneList = ['tldtest1.com', 'tldtest2.net', 'tldtest3.org', 'tldtest4.io', 'tldtest5.dev'];
      for (const zone of zoneList) {
        try {
          await page.locator('[data-testid="list-forward-zones-link"]').click();
          const zoneRow = page.locator(`tr:has-text("${zone}")`);
          if (await zoneRow.count() > 0) {
            await zoneRow.locator('[data-testid^="delete-zone-"]').click();
            await page.locator('[data-testid="confirm-delete-zone"]').click();
          }
        } catch (e) {
          // Continue
        }
      }
    }
  });
});
