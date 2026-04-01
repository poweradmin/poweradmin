import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
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
 */
test.describe.serial('IPv6 PTR Record Management (Issue #959)', () => {
  // Use unique zone name based on timestamp to avoid conflicts
  const timestamp = Date.now();
  const uniqueHex = (timestamp % 65536).toString(16).padStart(4, '0');
  const ipv6Zone = `${uniqueHex.split('').join('.')}.b.d.0.1.0.0.2.ip6.arpa`;
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should create IPv6 reverse zone successfully', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(ipv6Zone);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    const url = page.url();

    // Extract zone ID from modern URL pattern /zones/123/edit
    const zoneIdMatch = url.match(/\/zones\/(\d+)/);
    if (zoneIdMatch) {
      zoneId = zoneIdMatch[1];
    }

    const successAlert = page.locator('[data-testid="alert-message"], .alert-success, .alert');
    const hasSuccessMessage = await successAlert.filter({ hasText: /success|added/i }).count() > 0;
    const isOnEditPage = url.includes('/zones/') && url.includes('/edit');

    expect(hasSuccessMessage || isOnEditPage, 'Zone should be created successfully').toBe(true);
  });

  test('should add PTR record with user-specified nibbles (issue #959)', async ({ page }) => {
    // Find the zone we created
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() === 0) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found - run zone creation test first`);
      return;
    }

    // Get zone ID from edit link
    const editLink = zoneRow.locator('a[href*="/edit"]').first();
    const href = await editLink.getAttribute('href');
    const idMatch = href?.match(/\/zones\/(\d+)/);
    if (idMatch) {
      zoneId = idMatch[1];
    }

    // Navigate to add record page
    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const ptrNibbles = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
    const ptrContent = 'test-ipv6-host.example.com';

    const typeSelect = page.locator('select[name*="type"]').first();
    await typeSelect.selectOption('PTR');

    await page.locator('input[name*="[name]"]').first().fill(ptrNibbles);
    await page.locator('input[name*="[content]"]').first().fill(ptrContent);

    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const successAlert = page.locator('.alert-success, .alert').filter({ hasText: /success|added/i });
    const hasSuccess = await successAlert.count() > 0;

    if (!hasSuccess) {
      const errorAlert = page.locator('.alert-danger, .alert-warning');
      const errorText = await errorAlert.textContent().catch(() => 'No error message');
      console.log('Possible error:', errorText);
    }

    expect(hasSuccess, 'PTR record should be added successfully').toBe(true);
  });

  test('should verify PTR record name contains user input (issue #959 bug check)', async ({ page }) => {
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() === 0) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found`);
      return;
    }

    // Navigate to zone edit page
    const editLink = zoneRow.locator('a[href*="/edit"]').first();
    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Look for PTR records in the zone
    const ptrNibbles = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
    const expectedContent = 'test-ipv6-host.example.com';

    // Record content is in input fields, not text nodes - find by input value
    const contentInput = page.locator(`input[value*="${expectedContent}"]`).first();
    const recordRow = contentInput.locator('xpath=ancestor::tr');
    if (await recordRow.count() === 0) {
      const anyPtrRecords = page.locator('tr').filter({ hasText: 'PTR' });
      const ptrCount = await anyPtrRecords.count();
      console.log(`Found ${ptrCount} PTR record(s) in zone`);

      test.skip(true, 'PTR record not found - run record creation test first');
      return;
    }

    const nameInput = recordRow.locator('input[name*="[name]"]').first();
    let recordName = '';
    if (await nameInput.count() > 0) {
      recordName = await nameInput.inputValue();
    } else {
      const nameCell = recordRow.locator('td').first();
      recordName = (await nameCell.textContent()) || '';
    }

    expect(recordName, 'Record name should not be empty').not.toBe('');

    const normalizedRecordName = recordName.trim().toLowerCase();
    const normalizedZoneName = ipv6Zone.toLowerCase();
    const isJustZoneName = normalizedRecordName === normalizedZoneName || normalizedRecordName === '@';
    expect(isJustZoneName, `BUG #959: Record name should not be just the zone name "${ipv6Zone}". Got: "${recordName}"`).toBe(false);

    const startsWithNibbles = recordName.startsWith('1.0.0.0') || recordName.startsWith('0.0.0.0');
    expect(startsWithNibbles, `Record name should start with user-entered nibbles. Got: "${recordName}"`).toBe(true);

    const expectedFqdnSuffix = `.${ipv6Zone}`;
    const isFullFqdn = normalizedRecordName.endsWith(expectedFqdnSuffix.toLowerCase());
    const isHostnameOnly = !normalizedRecordName.includes('.ip6.arpa');
    expect(isFullFqdn || isHostnameOnly, `Record name should be valid format. Got: "${recordName}"`).toBe(true);
  });

  test('should edit PTR record and preserve name correctly', async ({ page }) => {
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() === 0) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found`);
      return;
    }

    const editLink = zoneRow.locator('a[href*="/edit"]').first();
    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Extract zone ID from URL
    const url = page.url();
    const zoneIdMatch = url.match(/\/zones\/(\d+)/);
    if (zoneIdMatch) {
      zoneId = zoneIdMatch[1];
    }

    // Find a PTR record with our test content (content is in input fields)
    const expectedContent = 'test-ipv6-host.example.com';
    const contentInput = page.locator(`input[value*="${expectedContent}"]`).first();
    const recordRow = contentInput.locator('xpath=ancestor::tr');
    if (await contentInput.count() === 0) {
      test.skip(true, 'PTR record not found for editing test');
      return;
    }

    // Get the name field value from the inline edit form on the zone edit page
    const nameInput = recordRow.locator('input[name*="[name]"]').first();
    if (await nameInput.count() === 0) {
      test.skip(true, 'Name input not found for PTR record');
      return;
    }

    const nameValue = await nameInput.inputValue();

    expect(nameValue, 'Name field should not be empty').not.toBe('');

    const normalizedNameValue = nameValue.trim().toLowerCase();
    const normalizedZoneName = ipv6Zone.toLowerCase();
    const isApexOrZone = normalizedNameValue === '@' || normalizedNameValue === normalizedZoneName;
    expect(isApexOrZone, `BUG #959: Edit form should show user's nibbles, not zone name "${ipv6Zone}". Got: "${nameValue}"`).toBe(false);

    const startsWithNibbles = nameValue.startsWith('1.0.0.0') || nameValue.startsWith('0.0.0.0');
    expect(startsWithNibbles, `Edit form should preserve nibble input at start. Got: "${nameValue}"`).toBe(true);
  });

  test('should delete IPv6 reverse zone (cleanup)', async ({ page }) => {
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() === 0) {
      return;
    }

    const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
    await deleteLink.click();
    await page.waitForLoadState('networkidle');

    const confirmButton = page.locator('[data-testid="confirm-delete-zone"], button[type="submit"]').first();
    await confirmButton.click();
    await page.waitForLoadState('networkidle');

    const successAlert = page.locator('.alert-success, .alert').filter({ hasText: /success|deleted/i });
    const hasSuccess = await successAlert.count() > 0;

    expect(hasSuccess, 'Zone should be deleted successfully').toBe(true);
  });
});

/**
 * Additional test for shorter IPv6 nibble sequences
 */
test.describe('IPv6 PTR - Short Nibble Sequence', () => {
  const timestamp = Date.now();
  const uniqueHex = ((timestamp + 1) % 65536).toString(16).padStart(4, '0');
  const ipv6Zone = `0.0.0.0.0.0.0.0.0.0.${uniqueHex.split('').join('.')}.b.d.0.1.0.0.2.ip6.arpa`;
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should handle short nibble input correctly', async ({ page }) => {
    const simpleZone = `${uniqueHex.slice(0, 2)}.8.b.d.0.1.0.0.2.ip6.arpa`;

    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');
    await page.locator('[data-testid="zone-name-input"]').fill(simpleZone);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.toLowerCase().includes('already exists') || bodyText.toLowerCase().includes('error')) {
      test.skip('Zone already exists or error creating zone');
      return;
    }

    const url = page.url();
    let zoneIdMatch = url.match(/\/zones\/(\d+)/);
    if (!zoneIdMatch) {
      await page.goto('/zones/reverse?letter=all');
      const zoneRow = page.locator(`tr:has-text("${simpleZone}")`);
      if (await zoneRow.count() === 0) {
        test.skip('Could not create zone');
        return;
      }
      const editLink = zoneRow.locator('a[href*="/edit"]').first();
      const href = await editLink.getAttribute('href');
      zoneIdMatch = href?.match(/\/zones\/(\d+)/);
    }
    if (!zoneIdMatch) {
      test.skip('Could not find zone ID');
      return;
    }
    zoneId = zoneIdMatch[1];

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    const shortNibbles = 'a.b.c.d';
    const ptrContent = 'short-nibble-test.example.com';

    const typeSelect = page.locator('select[name*="type"], select.record-type-select').first();
    if (await typeSelect.count() === 0) {
      test.skip('Record form not found on edit page');
      return;
    }

    await typeSelect.selectOption('PTR');

    const nameField = page.locator('[data-testid="record-name-input"], input.name-field, input[name*="[name]"]').first();
    if (await nameField.count() === 0) {
      test.skip('Name field not found');
      return;
    }
    await nameField.fill(shortNibbles);

    const contentField = page.locator('[data-testid="record-content-input"], input.record-content, input[name*="[content]"]').first();
    if (await contentField.count() === 0) {
      test.skip('Content field not found');
      return;
    }
    await contentField.fill(ptrContent);

    const submitBtn = page.locator('[data-testid="add-record-button"], button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    const pageContent = await page.locator('body').textContent();
    expect(pageContent).not.toMatch(/fatal|exception/i);

    // Cleanup - delete the zone
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator('table tbody tr').filter({ hasText: simpleZone });
    if (await zoneRow.count() > 0) {
      const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
      await deleteLink.click();
      await page.waitForLoadState('networkidle');

      const confirmButton = page.locator('input[value="Yes"], button:has-text("Yes"), [data-testid="confirm-delete-zone"]').first();
      await confirmButton.click();
    }
  });
});
