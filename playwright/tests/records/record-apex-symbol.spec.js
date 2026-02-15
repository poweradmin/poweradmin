import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

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

// Run tests serially as they depend on shared zone
test.describe.configure({ mode: 'serial' });

test.describe('Zone Apex (@) Symbol Handling', () => {
  const timestamp = Date.now();
  const testDomain = `apex-test-${timestamp}.com`;
  let zoneCreated = false;
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should create test zone', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(testDomain);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/forward?letter=all');
    const zoneRow = page.locator(`tr:has-text("${testDomain}")`);
    await expect(zoneRow).toBeVisible();
    const editLink = await zoneRow.locator('a[href*="/edit"]').first().getAttribute('href');
    const match = editLink.match(/\/zones\/(\d+)/);
    if (match) {
      zoneId = match[1];
    }
    zoneCreated = true;
  });

  test('should convert @ to zone name when adding a record', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    // Fill form with @ as the name
    await page.locator('input[name*="name"]').first().fill('@');
    await page.locator('select[name*="type"]').first().selectOption('TXT');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('"v=spf1 mx -all"');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Should redirect to zone edit page with success message
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText).toContain('successfully');

    // Verify the record name is the zone name, not @.zone or @
    const recordNameInput = page.locator('input[name*="name"]').last();
    const recordName = await recordNameInput.inputValue();
    expect(recordName).not.toContain('@');
    expect(recordName).toContain(testDomain);
  });

  test('should convert empty name to zone name when adding a record', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    // Leave name empty (should also resolve to zone apex)
    await page.locator('input[name*="name"]').first().fill('');
    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('192.0.2.1');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText).toContain('successfully');

    // Find the A record row and verify name
    const aRecordRow = page.locator('tr:has-text("192.0.2.1")');
    if (await aRecordRow.count() > 0) {
      const nameInput = aRecordRow.locator('input[name*="name"]').first();
      const recordName = await nameInput.inputValue();
      expect(recordName).toContain(testDomain);
    }
  });

  test('should cleanup test zone', async ({ page }) => {
    test.skip(!zoneCreated, 'Zone not created');

    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${testDomain}")`).locator('a[href*="/delete"]').first().click();

    const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
    await yesBtn.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
