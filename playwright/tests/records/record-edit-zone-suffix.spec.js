import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId, getZoneInfo } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

/**
 * Test for GitHub issue #958: Editing a DNS record removes "Zone" from Name field
 *
 * When a record name contains the zone name (e.g., "test.example.com.abc" in zone "example.com"),
 * editing the record should only strip the trailing zone suffix, not all occurrences.
 *
 * Bug: str_replace() was used which replaces ALL occurrences of zone name
 * Fix: Use suffix-stripping logic that only removes the trailing zone name
 *
 * Note: The display format depends on the `display_hostname_only` config setting:
 * - When true: Shows hostname only (zone suffix stripped), e.g., "test.example.com.sub"
 * - When false (default): Shows full FQDN, e.g., "test.example.com.sub.example.com"
 *
 * In both cases, the record name should NOT contain double dots (..) which was the bug symptom.
 */
test.describe('Record Edit - Zone Suffix Stripping (Issue #958)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should preserve zone name in record name when editing (issue #958)', async ({ page }) => {
    const zoneId = await getTestZoneId(page, 'admin');
    expect(zoneId, 'admin-zone.example.com must exist in the standard test data').toBeTruthy();
    const zoneName = getZoneInfo('admin').name;

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
    // Use search filter to find the record across paginated pages
    await page.goto(`/zones/${zoneId}/edit?search=${uniquePrefix}`);
    await page.waitForLoadState('networkidle');

    // Find the record input (exclude the search input by matching name attribute)
    const recordNameInput = page.locator(`input[name*="record"][name*="[name]"][value*="${uniquePrefix}"]`).first();
    await expect(recordNameInput).toBeVisible({ timeout: 10000 });

    // Get the record ID from the input name (format: record[ID][name])
    // On API backend, ID may be an encoded string, not just digits
    const inputName = await recordNameInput.getAttribute('name');
    const recordIdMatch = inputName?.match(/record\[([^\]]+)\]/);
    expect(recordIdMatch, 'record id must be present in the input name').toBeTruthy();

    const recordId = recordIdMatch[1];

    // Navigate directly to the single-record edit page
    await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
    await page.waitForLoadState('networkidle');

    // Get the name field value from the single-record edit page
    const nameValue = await page.locator('input[name*="name"]').first().inputValue();

    // THE BUG CHECK: Name should NOT contain double dots (..)
    // Bug causes: "{prefix}..sub" (zone name stripped from ALL occurrences)
    // Correct behavior depends on display_hostname_only config:
    // - When true: "{prefix}.{zoneName}.sub" (only trailing zone suffix removed)
    // - When false (default): "{prefix}.{zoneName}.sub.{zoneName}" (full FQDN)
    expect(nameValue, 'Name should not contain ".." (double dots indicate bug #958)').not.toContain('..');
    expect(nameValue, `Name should contain zone name "${zoneName}"`).toContain(zoneName);

    // The displayed value is either hostname-only or full FQDN depending on display_hostname_only config
    const expectedFullName = `${recordHostname}.${zoneName}`;
    const isHostnameOnly = nameValue === recordHostname;
    const isFullFqdn = nameValue === expectedFullName;
    expect(isHostnameOnly || isFullFqdn, `Name should be either "${recordHostname}" (hostname-only) or "${expectedFullName}" (full FQDN), got "${nameValue}"`).toBe(true);
  });

  test('should handle simple record names correctly', async ({ page }) => {
    const zoneId = await getTestZoneId(page, 'admin');
    expect(zoneId, 'admin-zone.example.com must exist in the standard test data').toBeTruthy();
    const zoneName = getZoneInfo('admin').name;

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

    const recordNameInput = page.locator(`input[name^="record["][name$="][name]"][value*="${uniqueHostname}"]`).first();
    await expect(recordNameInput, 'the record added above must be listed on the zone edit page').toBeVisible();

    // The record id is numeric on SQL and an encoded string on the API backend
    const inputName = await recordNameInput.getAttribute('name');
    const recordIdMatch = inputName?.match(/record\[([^\]]+)\]/);
    expect(recordIdMatch, 'record id must be present in the input name').toBeTruthy();
    const recordId = recordIdMatch[1];

    // Navigate directly to the single-record edit page
    await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
    await page.waitForLoadState('networkidle');

    // Simple hostname display depends on display_hostname_only config:
    // - When true: Shows hostname only, e.g., "simple123456"
    // - When false (default): Shows full FQDN, e.g., "simple123456.example.com"
    const editNameInput = page.locator('input[name*="name"]').first();
    await expect(editNameInput).toBeVisible();
    const nameValue = await editNameInput.inputValue();

    const expectedFullName = `${uniqueHostname}.${zoneName}`;
    const isHostnameOnly = nameValue === uniqueHostname;
    const isFullFqdn = nameValue === expectedFullName;

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    expect(nameValue).toContain(uniqueHostname);
    // The field shows either the host on its own or the full name, per the
    // display_hostname_only setting; anything else is a formatting bug
    expect(isHostnameOnly || isFullFqdn).toBe(true);
  });
});
