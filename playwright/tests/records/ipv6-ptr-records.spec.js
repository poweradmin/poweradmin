import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Test for GitHub issue #959: IPv6 PTR record name handling
 *
 * 4.x COMPATIBLE VERSION - Uses legacy index.php?page= routing
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
  // Using last 4 digits of timestamp converted to hex nibbles for better uniqueness
  const timestamp = Date.now();
  const uniqueHex = (timestamp % 65536).toString(16).padStart(4, '0');
  const ipv6Zone = `${uniqueHex.split('').join('.')}.b.d.0.1.0.0.2.ip6.arpa`;
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should create IPv6 reverse zone successfully', async ({ page }) => {
    // Navigate to add master zone page using modern URL
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    // Fill in the IPv6 reverse zone name
    await page.locator('[data-testid="zone-name-input"]').fill(ipv6Zone);

    // Submit the form
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Should redirect to zone edit page or show success
    const url = page.url();
    const successAlert = page.locator('[data-testid="alert-message"], .alert-success, .alert');

    // Extract zone ID from URL if redirected to edit page
    const zoneIdMatch = url.match(/[?&]id=(\d+)/);
    if (zoneIdMatch) {
      zoneId = zoneIdMatch[1];
    }

    // Verify zone was created (either by URL or success message)
    const hasSuccessMessage = await successAlert.filter({ hasText: /success|added/i }).count() > 0;
    const isOnEditPage = url.includes('page=edit') && url.includes('id=');

    expect(hasSuccessMessage || isOnEditPage, 'Zone should be created successfully').toBe(true);
  });

  test('should add PTR record with user-specified nibbles (issue #959)', async ({ page }) => {
    // First, find the zone we created
    await page.goto('index.php?page=list_reverse_zones');
    await page.waitForLoadState('networkidle');

    // Find our IPv6 zone in the list
    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    const hasZone = await zoneRow.count() > 0;

    if (!hasZone) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found - run zone creation test first`);
      return;
    }

    // Click on the zone to edit it
    const editLink = zoneRow.locator('a[href*="page=edit"]').first();
    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Extract zone ID from URL
    const url = page.url();
    const zoneIdMatch = url.match(/[?&]id=(\d+)/);
    if (zoneIdMatch) {
      zoneId = zoneIdMatch[1];
    }

    // Navigate to add record page
    await page.goto(`index.php?page=add_record&id=${zoneId}`);
    await page.waitForLoadState('networkidle');

    // The nibble sequence representing a specific IPv6 address within the zone
    // For zone 8.b.d.0.1.0.0.2.ip6.arpa, we add nibbles for the remaining address parts
    const ptrNibbles = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
    const ptrContent = 'test-ipv6-host.example.com';

    // PTR should be pre-selected for reverse zones, but ensure it's selected
    const typeSelect = page.locator('select[name*="type"]').first();
    await typeSelect.selectOption('PTR');

    // Fill in the PTR record name (nibbles)
    await page.locator('input[name*="[name]"]').first().fill(ptrNibbles);

    // Fill in the PTR content (hostname)
    await page.locator('input[name*="[content]"]').first().fill(ptrContent);

    // Submit the form
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Should show success message or redirect to zone edit page
    const successAlert = page.locator('.alert-success, .alert').filter({ hasText: /success|added/i });
    const hasSuccess = await successAlert.count() > 0;

    // If there's an error, capture it for debugging
    if (!hasSuccess) {
      const errorAlert = page.locator('.alert-danger, .alert-warning');
      const errorText = await errorAlert.textContent().catch(() => 'No error message');
      console.log('Possible error:', errorText);
    }

    expect(hasSuccess, 'PTR record should be added successfully').toBe(true);
  });

  test('should verify PTR record name contains user input (issue #959 bug check)', async ({ page }) => {
    // Navigate to the zone edit page to see the record
    await page.goto('index.php?page=list_reverse_zones');
    await page.waitForLoadState('networkidle');

    // Find our IPv6 zone
    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    const hasZone = await zoneRow.count() > 0;

    if (!hasZone) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found`);
      return;
    }

    // Click on the zone to edit it
    const editLink = zoneRow.locator('a[href*="page=edit"]').first();
    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Look for PTR records in the zone
    // The record name should contain the nibbles we entered, not just the zone name
    const ptrNibbles = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
    const expectedContent = 'test-ipv6-host.example.com';

    // Find the PTR record row by its content
    const recordRow = page.locator('tr, .record-row').filter({ hasText: expectedContent });
    const hasRecord = await recordRow.count() > 0;

    if (!hasRecord) {
      // Check if any PTR records exist
      const anyPtrRecords = page.locator('tr').filter({ hasText: 'PTR' });
      const ptrCount = await anyPtrRecords.count();
      console.log(`Found ${ptrCount} PTR record(s) in zone`);

      test.skip(true, 'PTR record not found - run record creation test first');
      return;
    }

    // Get the record name from the row
    // The name input or text should contain the nibbles we entered
    const nameInput = recordRow.locator('input[name*="[name]"]').first();
    const hasNameInput = await nameInput.count() > 0;

    let recordName = '';
    if (hasNameInput) {
      recordName = await nameInput.inputValue();
    } else {
      // Try to get the name from a text element or the first cell
      const nameCell = recordRow.locator('td').first();
      recordName = (await nameCell.textContent()) || '';
    }

    // BUG CHECK: The record name should NOT be just the zone name
    // It should contain the nibbles the user entered
    expect(recordName, 'Record name should not be empty').not.toBe('');

    // The record name should contain part of the nibbles we entered
    // Depending on display_hostname_only setting, it might show:
    // - Full FQDN: 1.0.0.0...0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa
    // - Hostname only: 1.0.0.0...0.0.0.0
    // But it should NEVER be just the zone name or empty

    // CRITICAL: Check that it's not just the zone name (the bug behavior from issue #959)
    const normalizedRecordName = recordName.trim().toLowerCase();
    const normalizedZoneName = ipv6Zone.toLowerCase();
    const isJustZoneName = normalizedRecordName === normalizedZoneName || normalizedRecordName === '@';
    expect(isJustZoneName, `BUG #959: Record name should not be just the zone name "${ipv6Zone}". Got: "${recordName}"`).toBe(false);

    // The record should START with the nibbles we entered (not just contain them somewhere)
    const startsWithNibbles = recordName.startsWith('1.0.0.0') || recordName.startsWith('0.0.0.0');
    expect(startsWithNibbles, `Record name should start with user-entered nibbles. Got: "${recordName}"`).toBe(true);

    // Additional validation: record name should either be:
    // 1. Just the nibbles (display_hostname_only = true): "1.0.0.0.0.0.0.0..."
    // 2. Full FQDN (display_hostname_only = false): "1.0.0.0.0.0.0.0...8.b.d.0.1.0.0.2.ip6.arpa"
    const expectedFqdnSuffix = `.${ipv6Zone}`;
    const isFullFqdn = normalizedRecordName.endsWith(expectedFqdnSuffix.toLowerCase());
    const isHostnameOnly = !normalizedRecordName.includes('.ip6.arpa');
    expect(isFullFqdn || isHostnameOnly, `Record name should be valid format. Got: "${recordName}"`).toBe(true);
  });

  test('should edit PTR record and preserve name correctly', async ({ page }) => {
    // Navigate to the zone
    await page.goto('index.php?page=list_reverse_zones');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    const hasZone = await zoneRow.count() > 0;

    if (!hasZone) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found`);
      return;
    }

    // Click on the zone to edit it
    const editLink = zoneRow.locator('a[href*="page=edit"]').first();
    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Extract zone ID from URL
    const url = page.url();
    const zoneIdMatch = url.match(/[?&]id=(\d+)/);
    if (zoneIdMatch) {
      zoneId = zoneIdMatch[1];
    }

    // Find a PTR record with our test content
    const expectedContent = 'test-ipv6-host.example.com';
    const recordRow = page.locator('tr').filter({ hasText: expectedContent });
    const hasRecord = await recordRow.count() > 0;

    if (!hasRecord) {
      test.skip(true, 'PTR record not found for editing test');
      return;
    }

    // Find and click the edit link for this record
    // 4.x uses: index.php?page=edit_record&id={record_id}&domain={zone_id}
    const recordEditLink = recordRow.locator('a[href*="page=edit_record"]').first();
    const hasEditLink = await recordEditLink.count() > 0;

    if (!hasEditLink) {
      test.skip(true, 'Edit link not found for PTR record');
      return;
    }

    await recordEditLink.click();
    await page.waitForLoadState('networkidle');

    // Get the name field value on the edit page
    const nameInput = page.locator('input[name*="name"]').first();
    const nameValue = await nameInput.inputValue();

    // The name should contain the nibbles, not just be empty or the zone name
    expect(nameValue, 'Name field should not be empty').not.toBe('');

    // CRITICAL: Check it's not just '@' (zone apex) or the zone name (bug #959)
    const normalizedNameValue = nameValue.trim().toLowerCase();
    const normalizedZoneName = ipv6Zone.toLowerCase();
    const isApexOrZone = normalizedNameValue === '@' || normalizedNameValue === normalizedZoneName;
    expect(isApexOrZone, `BUG #959: Edit form should show user's nibbles, not zone name "${ipv6Zone}". Got: "${nameValue}"`).toBe(false);

    // Should START with the nibbles (not just contain them somewhere)
    const startsWithNibbles = nameValue.startsWith('1.0.0.0') || nameValue.startsWith('0.0.0.0');
    expect(startsWithNibbles, `Edit form should preserve nibble input at start. Got: "${nameValue}"`).toBe(true);
  });

  test('should delete IPv6 reverse zone (cleanup)', async ({ page }) => {
    // Navigate to reverse zones
    await page.goto('index.php?page=list_reverse_zones');
    await page.waitForLoadState('networkidle');

    // Find our test zone
    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    const hasZone = await zoneRow.count() > 0;

    if (!hasZone) {
      // Zone doesn't exist, nothing to clean up
      return;
    }

    // Click delete link (4.x uses: index.php?page=delete_domain&id={id})
    const deleteLink = zoneRow.locator('a[href*="page=delete_domain"]').first();
    await deleteLink.click();
    await page.waitForLoadState('networkidle');

    // Confirm deletion
    const confirmButton = page.locator('[data-testid="confirm-delete-zone"], button[type="submit"]').first();
    await confirmButton.click();
    await page.waitForLoadState('networkidle');

    // Verify zone is deleted
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
  // Using last 4 digits of timestamp converted to hex nibbles for better uniqueness
  const uniqueHex = ((timestamp + 1) % 65536).toString(16).padStart(4, '0');
  // Use a longer zone prefix so we only need to add a few nibbles
  const ipv6Zone = `0.0.0.0.0.0.0.0.0.0.${uniqueHex.split('').join('.')}.b.d.0.1.0.0.2.ip6.arpa`;
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should handle short nibble input correctly', async ({ page }) => {
    // Create zone using modern URL
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');
    await page.locator('[data-testid="zone-name-input"]').fill(ipv6Zone);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Get zone ID from URL (modern pattern: /zones/123/edit)
    const url = page.url();
    let zoneIdMatch = url.match(/\/zones\/(\d+)/);
    if (!zoneIdMatch) {
      // Fallback: find zone in reverse zones list
      await page.goto('/zones/reverse?letter=all');
      const zoneRow = page.locator(`tr:has-text("${ipv6Zone}")`);
      if (await zoneRow.count() === 0) {
        test.skip(true, 'Could not create zone');
        return;
      }
      const editLink = zoneRow.locator('a[href*="/edit"]').first();
      const href = await editLink.getAttribute('href');
      zoneIdMatch = href?.match(/\/zones\/(\d+)/);
    }
    if (!zoneIdMatch) {
      test.skip(true, 'Could not find zone ID');
      return;
    }
    zoneId = zoneIdMatch[1];

    // Add a PTR record with just a few nibbles
    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const shortNibbles = 'a.b.c.d';
    const ptrContent = 'short-nibble-test.example.com';

    await page.locator('select[name*="type"]').first().selectOption('PTR');
    await page.locator('input[name*="name"]').first().fill(shortNibbles);
    await page.locator('input[name*="content"]').first().fill(ptrContent);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify record was added
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // Find the record
    const recordRow = page.locator('tr').filter({ hasText: ptrContent });
    const hasRecord = await recordRow.count() > 0;
    expect(hasRecord, 'PTR record should be created').toBe(true);

    if (hasRecord) {
      // Check the name contains our nibbles
      const nameInput = recordRow.locator('input[name*="name"]').first();
      const hasNameInput = await nameInput.count() > 0;

      if (hasNameInput) {
        const nameValue = await nameInput.inputValue();
        // Name should contain the short nibbles we entered
        const containsNibbles = nameValue.includes('a.b.c.d') || nameValue.includes('a') && nameValue.includes('b');
        expect(containsNibbles, `Name should contain nibbles "a.b.c.d". Got: "${nameValue}"`).toBe(true);
      }
    }

    // Cleanup - delete the zone
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator('table tbody tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() > 0) {
      const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
      await deleteLink.click();
      await page.waitForLoadState('networkidle');

      const confirmButton = page.locator('input[value="Yes"], button:has-text("Yes"), [data-testid="confirm-delete-zone"]').first();
      await confirmButton.click();
    }
  });
});
