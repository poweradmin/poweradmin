import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Test for GitHub issue #958: Editing a DNS record removes "Zone" from Name field
 *
 * When a record name contains the zone name (e.g., "test.example.com.abc" in zone "example.com"),
 * editing the record should only strip the trailing zone suffix, not all occurrences.
 *
 * Bug: str_replace() was used which replaces ALL occurrences of zone name
 * Fix: Use suffix-stripping logic that only removes the trailing zone name
 */
test.describe('Record Edit - Zone Suffix Stripping (Issue #958)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should preserve zone name in record name when editing (issue #958)', async ({ page }) => {
    // Navigate to forward zones page
    await page.goto('/zones/forward');

    const hasZones = await page.locator('table tbody tr').count() > 0;

    if (!hasZones) {
      test.skip(true, 'No zones available');
      return;
    }

    // Click on first zone to get to edit page
    const firstZoneRow = page.locator('table tbody tr').first();
    const editLink = firstZoneRow.locator('a[href*="/edit"]').first();
    const href = await editLink.getAttribute('href');
    const zoneIdMatch = href?.match(/\/zones\/(\d+)/);

    if (!zoneIdMatch) {
      test.skip(true, 'Could not extract zone ID');
      return;
    }

    const zoneId = zoneIdMatch[1];

    // Get zone name from the row - second cell (first is checkbox)
    const zoneNameCell = firstZoneRow.locator('td').nth(1);
    const zoneName = (await zoneNameCell.textContent())?.trim();

    if (!zoneName || !zoneName.includes('.')) {
      test.skip(true, 'Could not determine zone name');
      return;
    }

    // Create a record with zone name embedded in hostname
    const timestamp = Date.now();
    const uniquePrefix = `bug958t${String(timestamp).slice(-5)}`;
    const recordHostname = `${uniquePrefix}.${zoneName}.sub`;

    // Add the record
    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(recordHostname);
    await page.locator('input[name*="content"]').first().fill('192.168.99.99');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Navigate to zone edit page to find our record's ID
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // Find the record input and get the record ID from the hidden input
    const recordNameInput = page.locator(`input[value*="${uniquePrefix}"]`).first();
    await expect(recordNameInput).toBeVisible({ timeout: 10000 });

    // Get the record ID from the input name (format: record[ID][name])
    const inputName = await recordNameInput.getAttribute('name');
    const recordIdMatch = inputName?.match(/record\[(\d+)\]/);

    if (!recordIdMatch) {
      test.skip(true, 'Could not find record ID');
      return;
    }

    const recordId = recordIdMatch[1];

    // Navigate directly to the single-record edit page
    await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
    await page.waitForLoadState('networkidle');

    // Get the name field value from the single-record edit page
    const nameValue = await page.locator('input[name*="name"]').first().inputValue();

    // THE BUG CHECK: Name should NOT contain double dots (..)
    // Bug causes: "{prefix}..sub" (zone name stripped from ALL occurrences)
    // Correct: "{prefix}.{zoneName}.sub" (only trailing zone suffix removed)
    expect(nameValue, 'Name should not contain ".." (double dots indicate bug #958)').not.toContain('..');
    expect(nameValue, `Name should contain zone name "${zoneName}"`).toContain(zoneName);
    expect(nameValue, 'Name should match entered hostname').toBe(recordHostname);
  });

  test('should handle simple record names correctly', async ({ page }) => {
    // Navigate to forward zones page
    await page.goto('/zones/forward');

    const hasZones = await page.locator('table tbody tr').count() > 0;

    if (!hasZones) {
      test.skip(true, 'No zones available');
      return;
    }

    // Click on first zone
    const firstZoneRow = page.locator('table tbody tr').first();
    const editLink = firstZoneRow.locator('a[href*="/edit"]').first();
    const href = await editLink.getAttribute('href');
    const zoneIdMatch = href?.match(/\/zones\/(\d+)/);

    if (!zoneIdMatch) {
      test.skip(true, 'Could not extract zone ID');
      return;
    }

    const zoneId = zoneIdMatch[1];
    const timestamp = Date.now();
    const uniqueHostname = `simple${String(timestamp).slice(-6)}`;

    // Create simple record
    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(uniqueHostname);
    await page.locator('input[name*="content"]').first().fill('192.168.99.98');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Find and edit the record
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    const recordNameInput = page.locator(`input[value*="${uniqueHostname}"]`).first();
    await expect(recordNameInput).toBeVisible({ timeout: 10000 });

    // Get the record ID from the input name
    const inputName = await recordNameInput.getAttribute('name');
    const recordIdMatch = inputName?.match(/record\[(\d+)\]/);

    if (!recordIdMatch) {
      test.skip(true, 'Could not find record ID');
      return;
    }

    const recordId = recordIdMatch[1];

    // Navigate directly to the single-record edit page
    await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
    await page.waitForLoadState('networkidle');

    // Simple hostname should remain unchanged
    const nameValue = await page.locator('input[name*="name"]').first().inputValue();
    expect(nameValue).toBe(uniqueHostname);
  });
});
