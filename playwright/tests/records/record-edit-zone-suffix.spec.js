import { test, expect } from '../../fixtures/test-fixtures.js';
import { zones, getTestZoneId } from '../../helpers/zones.js';
import { expectNoFatalError } from '../../helpers/validation.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

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

  test('should preserve zone name in record name when editing (issue #958)', async ({ adminPage: page }) => {
    // Use manager zone which is known to exist
    const zoneName = zones.manager.name; // manager-zone.example.com

    // Get zone ID using helper
    const zoneId = await getTestZoneId(page, 'manager');
    test.skip(!zoneId, `Zone ${zoneName} not found`);

    // Create a record with zone name embedded in hostname
    // hostname: {prefix}.{zoneName}.sub -> FQDN: {prefix}.{zoneName}.sub.{zoneName}
    const timestamp = Date.now();
    const uniquePrefix = `bug958t${String(timestamp).slice(-5)}`;
    const recordHostname = `${uniquePrefix}.${zoneName}.sub`;

    // Add the record
    await page.goto(`/index.php?page=add_record&id=${zoneId}`);
    await expectNoFatalError(page);

    // Fill in the form
    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(recordHostname);
    await page.locator('input[name*="content"]').first().fill('192.168.99.99');

    // Submit and wait
    await page.locator('button:has-text("Add record"), button:has-text("Add records")').first().click();
    await page.waitForLoadState('networkidle');

    // Navigate to the zone edit page
    await page.goto(`/index.php?page=edit&id=${zoneId}`);
    await expectNoFatalError(page);

    // Filter for our record
    const filterInput = page.locator('input[placeholder*="Search"]').first();
    await filterInput.fill(uniquePrefix);
    await page.locator('button:has-text("Apply")').first().click();
    await page.waitForLoadState('networkidle');

    // Wait for the filtered results to appear
    // The name is displayed in an input field, so look for input with value containing the prefix
    const recordRow = page.locator(`tr:has(input[value*="${uniquePrefix}"])`).first();
    await recordRow.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
    const rowCount = await recordRow.count();
    expect(rowCount, `Record with prefix "${uniquePrefix}" should have been created`).toBeGreaterThan(0);

    const editLink = recordRow.locator('a[href*="edit_record"]').first();
    await editLink.click();
    await page.waitForLoadState('networkidle');
    await expectNoFatalError(page);

    // Get the name field value
    const nameField = page.locator('input[name*="name"]').first();
    const nameValue = await nameField.inputValue();

    // THE BUG CHECK: The name should NOT contain double dots (..)
    // Bug causes: "{prefix}..sub" (zone name stripped from ALL occurrences)
    // Correct: "{prefix}.{zoneName}.sub" (only trailing zone suffix removed)
    expect(nameValue, 'Name should not contain ".." (double dots indicate bug #958)').not.toContain('..');

    // The zone name should still be present in the hostname
    expect(nameValue, `Name should contain "${zoneName}"`).toContain(zoneName);

    // The value should exactly match what we entered
    expect(nameValue, 'Name should match the entered hostname').toBe(recordHostname);
  });

  test('should handle simple record names correctly', async ({ adminPage: page }) => {
    // Baseline test - simple record without zone name in hostname
    const zoneId = await getTestZoneId(page, 'manager');
    test.skip(!zoneId, 'Zone not found');

    const timestamp = Date.now();
    const uniqueHostname = `simple${String(timestamp).slice(-6)}`;

    // Create simple record
    await page.goto(`/index.php?page=add_record&id=${zoneId}`);
    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(uniqueHostname);
    await page.locator('input[name*="content"]').first().fill('192.168.99.98');

    await page.locator('button:has-text("Add record"), button:has-text("Add records")').first().click();
    await page.waitForLoadState('networkidle');

    // Find and edit the record
    await page.goto(`/index.php?page=edit&id=${zoneId}`);

    const filterInput = page.locator('input[placeholder*="Search"]').first();
    await filterInput.fill(uniqueHostname);
    await page.locator('button:has-text("Apply")').first().click();
    await page.waitForLoadState('networkidle');

    const recordRow = page.locator(`tr:has(input[value*="${uniqueHostname}"])`).first();
    await recordRow.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
    const rowCount = await recordRow.count();
    expect(rowCount, 'Simple record should have been created').toBeGreaterThan(0);

    await recordRow.locator('a[href*="edit_record"]').first().click();
    await page.waitForLoadState('networkidle');

    // Simple hostname should remain unchanged
    const nameValue = await page.locator('input[name*="name"]').first().inputValue();
    expect(nameValue).toBe(uniqueHostname);
  });
});
