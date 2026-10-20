import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

/**
 * Tests for zone apex (@) symbol handling when adding records.
 *
 * The @ symbol is a conventional shorthand for the zone apex (root).
 * When a user enters @ as the record name, it should be stored as
 * the zone name itself (e.g., "example.com"), not as "@.example.com".
 *
 * Bug: The multi-record add form (addMultipleRecords) was not calling
 * DnsHelper::restoreZoneSuffix() to convert @ to the zone name.
 */

test.describe('Zone Apex (@) Symbol Handling', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should convert @ to zone name when adding a record', async ({ page, tempZone }) => {
    test.slow(); // creates throwaway zones on top of the test itself
    const zoneId = tempZone.id;
    const testDomain = tempZone.name;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    // Fill form with @ as the name
    await page.locator('input[name*="name"]').first().fill('@');
    await page.locator('select[name*="type"]').first().selectOption('TXT');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('"v=spf1 mx -all"');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Should redirect to zone edit page with success message
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await expect(page.locator('body')).toContainText('have been added successfully');

    // Verify the record name is the zone name, not @.zone or @
    const recordNameInput = page.locator('input[name*="name"]').last();
    const recordName = await recordNameInput.inputValue();
    expect(recordName).not.toContain('@');
    expect(recordName).toContain(testDomain);
  });

  test('should convert empty name to zone name when adding a record', async ({ page, tempZone }) => {
    test.slow(); // creates throwaway zones on top of the test itself
    const zoneId = tempZone.id;
    const testDomain = tempZone.name;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    // Leave name empty (should also resolve to zone apex)
    await page.locator('input[name*="name"]').first().fill('');
    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('192.0.2.1');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await expect(page.locator('body')).toContainText('have been added successfully');

    // Record values live in input attributes, so tr:has-text never matches them
    const aRecordRow = page.locator('tr:has(input[name$="[content]"][value="192.0.2.1"])');
    await expect(aRecordRow.locator('input[name$="[name]"]')).toHaveValue(testDomain);
  });
});
