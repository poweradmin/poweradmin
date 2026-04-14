import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Test for GitHub issue #959: IPv6 PTR record name handling
 *
 * When creating a PTR record in an IPv6 reverse zone, the record name should
 * preserve the user's input (nibble sequence) and append the zone suffix correctly.
 *
 * Bug: The full zone name is used as the record name regardless of user input.
 * Expected: User's nibble input + zone suffix = full PTR record name
 *
 * Example:
 * - Zone: 8.b.d.0.1.0.0.2.ip6.arpa
 * - User enters: 1.0.0.0.0.0.0.0 (nibbles for specific IPv6 address)
 * - Expected record name: 1.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa
 * - Bug behavior: 8.b.d.0.1.0.0.2.ip6.arpa (zone name only, user input ignored)
 *
 * Uses the pre-existing reverseIPv6 fixture zone (8.b.d.0.1.0.0.2.ip6.arpa)
 * created by global setup.
 */

const ipv6Zone = '8.b.d.0.1.0.0.2.ip6.arpa';
const ptrNibbles = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
const ptrContent = 'test-ipv6-host.example.com';

test.describe.serial('IPv6 PTR Record Management (Issue #959)', () => {
  let zoneId = null;
  let recordId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should find existing IPv6 reverse zone', async ({ page }) => {
    zoneId = await getTestZoneId(page, 'reverseIPv6');
    expect(zoneId, 'IPv6 reverse zone should exist (created by global setup)').toBeTruthy();
  });

  test('should add PTR record with user-specified nibbles (issue #959)', async ({ page }) => {
    if (!zoneId) {
      test.skip(true, 'IPv6 reverse zone not found');
      return;
    }

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    // Select PTR record type
    const typeSelect = page.locator('select[name*="type"]').first();
    await typeSelect.selectOption('PTR');

    // Fill in the PTR record name (nibbles) and content (hostname)
    await page.locator('input[name="records[0][name]"]').fill(ptrNibbles);
    await page.locator('input[name="records[0][content]"]').fill(ptrContent);

    // Submit the form
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // After adding, the app redirects to zone edit page
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|error occurred/i);

    // Records are in input fields on the zone edit page, so check for the input value
    const contentInput = page.locator(`input[value="${ptrContent}"]`);
    expect(await contentInput.count(), 'PTR record should appear on the zone edit page').toBeGreaterThan(0);
  });

  test('should verify PTR record name contains user input (issue #959 bug check)', async ({ page }) => {
    if (!zoneId) {
      test.skip(true, 'IPv6 reverse zone not found');
      return;
    }

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // Find PTR record with our test hostname in content input
    const allRows = page.locator('table tbody tr');
    const rowCount = await allRows.count();

    let recordName = '';
    let foundPtrRecord = false;

    for (let i = 0; i < rowCount; i++) {
      const row = allRows.nth(i);
      const rowText = await row.textContent();

      if (!rowText.includes('PTR') || rowText.includes('SOA')) continue;

      const contentInput = row.locator('input[name*="[content]"]').first();
      if (await contentInput.count() === 0) continue;

      const contentValue = await contentInput.inputValue();
      if (!contentValue.includes('test-ipv6-host')) continue;

      foundPtrRecord = true;
      const nameInput = row.locator('input[name*="[name]"]').first();
      if (await nameInput.count() > 0) {
        recordName = await nameInput.inputValue();
      }

      // Extract record ID for later tests (numeric on SQL, encoded string on API backend)
      const inputName = await contentInput.getAttribute('name');
      const idMatch = inputName?.match(/record\[([^\]]+)\]/);
      if (idMatch) {
        recordId = idMatch[1];
      }
      break;
    }

    if (!foundPtrRecord) {
      test.skip(true, 'PTR record with test content not found');
      return;
    }

    expect(recordName, 'Record name should not be empty').not.toBe('');

    // CRITICAL: Check that it's not just the zone name (the bug behavior from issue #959)
    const normalizedRecordName = recordName.trim().toLowerCase();
    const isJustZoneName = normalizedRecordName === ipv6Zone || normalizedRecordName === '@';
    expect(isJustZoneName, `BUG #959: Record name should not be just the zone name "${ipv6Zone}". Got: "${recordName}"`).toBe(false);

    // The record should contain the nibbles we entered
    const containsNibbles = normalizedRecordName.includes('1.0.0.0') || normalizedRecordName.includes('0.0.0.0');
    expect(containsNibbles, `Record name should contain user-entered nibbles. Got: "${recordName}"`).toBe(true);
  });

  test('should edit PTR record and preserve name correctly', async ({ page }) => {
    if (!zoneId || !recordId) {
      test.skip(true, 'Zone or record ID not available');
      return;
    }

    await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
    await page.waitForLoadState('networkidle');

    const nameInput = page.locator('input[name="name"]');
    const nameValue = await nameInput.inputValue();

    expect(nameValue, 'Name field should not be empty').not.toBe('');

    // CRITICAL: Check it's not just '@' or the zone name (bug #959)
    const normalizedNameValue = nameValue.trim().toLowerCase();
    const isApexOrZone = normalizedNameValue === '@' || normalizedNameValue === ipv6Zone;
    expect(isApexOrZone, `BUG #959: Edit form should show user's nibbles, not zone name "${ipv6Zone}". Got: "${nameValue}"`).toBe(false);

    const containsNibbles = normalizedNameValue.includes('1.0.0.0') || normalizedNameValue.includes('0.0.0.0');
    expect(containsNibbles, `Edit form should preserve nibble input. Got: "${nameValue}"`).toBe(true);
  });

  test('should add PTR record with short nibble sequence', async ({ page }) => {
    if (!zoneId) {
      test.skip(true, 'IPv6 reverse zone not found');
      return;
    }

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const shortNibbles = 'a.b.c.d';
    const shortPtrContent = 'short-nibble-test.example.com';

    const typeSelect = page.locator('select[name*="type"]').first();
    await typeSelect.selectOption('PTR');
    await page.locator('input[name="records[0][name]"]').fill(shortNibbles);
    await page.locator('input[name="records[0][content]"]').fill(shortPtrContent);

    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should clean up test PTR records', async ({ page }) => {
    if (!zoneId) {
      return;
    }

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // Find and delete test PTR records by their content
    const testContents = ['test-ipv6-host', 'short-nibble-test'];
    const allRows = page.locator('table tbody tr');
    const rowCount = await allRows.count();
    const recordIdsToDelete = [];

    for (let i = 0; i < rowCount; i++) {
      const row = allRows.nth(i);
      const contentInput = row.locator('input[name*="[content]"]').first();
      if (await contentInput.count() === 0) continue;

      const contentValue = await contentInput.inputValue();
      if (testContents.some(tc => contentValue.includes(tc))) {
        const inputName = await contentInput.getAttribute('name');
        const idMatch = inputName?.match(/record\[(\d+)\]/);
        if (idMatch) {
          recordIdsToDelete.push(idMatch[1]);
        }
      }
    }

    // Delete each test record
    for (const rid of recordIdsToDelete) {
      await page.goto(`/zones/${zoneId}/records/${rid}/delete`);
      await page.waitForLoadState('networkidle');

      const confirmButton = page.locator('input[value="Yes"], button:has-text("Yes"), [data-testid="confirm-delete-record"]').first();
      if (await confirmButton.count() > 0) {
        await confirmButton.click();
        await page.waitForLoadState('networkidle');
      }
    }

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
