import { test, expect } from '../../fixtures/test-fixtures.js';

/**
 * Test for GitHub issue #959: IPv6 PTR record name handling
 *
 * When creating a PTR record in an IPv6 reverse zone, the record name should
 * preserve the user's input (nibble sequence) and append the zone suffix correctly.
 *
 * Uses the existing fixture zone: 8.b.d.0.1.0.0.2.ip6.arpa
 */

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('IPv6 PTR Record Management (Issue #959)', () => {
  // Use the existing fixture zone instead of creating a new one
  const ipv6Zone = '8.b.d.0.1.0.0.2.ip6.arpa';
  let zoneId = null;

  // Use fixed test data to ensure consistency across tests
  // The nibbles represent a specific IPv6 address within the zone
  const ptrNibbles = '1.2.3.4.5.6.7.8.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0';
  const ptrContent = 'ipv6-ptr-test.example.com';

  test('should find existing IPv6 reverse zone', async ({ adminPage: page }) => {
    // Navigate to reverse zones list
    await page.goto('/index.php?page=list_reverse_zones');

    // Find our IPv6 zone in the list
    const zoneRow = page.locator('tr').filter({ hasText: ipv6Zone });
    const hasZone = await zoneRow.count() > 0;

    expect(hasZone, `IPv6 zone ${ipv6Zone} should exist in fixtures`).toBe(true);

    if (hasZone) {
      // Get zone ID from the edit link
      const editLink = zoneRow.locator('a[href*="page=edit"]').first();
      const href = await editLink.getAttribute('href');
      const match = href?.match(/id=(\d+)/);
      if (match) {
        zoneId = match[1];
      }
    }
  });

  test('should add PTR record with user-specified nibbles (issue #959)', async ({ adminPage: page }) => {
    // First, get the zone ID from the reverse zones list
    await page.goto('/index.php?page=list_reverse_zones');

    const zoneRow = page.locator('tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() === 0) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found`);
      return;
    }

    // Get zone ID from the edit link
    const editLink = zoneRow.locator('a[href*="page=edit"]').first();
    const href = await editLink.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (match) {
      zoneId = match[1];
    }

    expect(zoneId, 'Zone ID should be found').toBeTruthy();

    // Navigate directly to add record page (like working tests do)
    await page.goto(`/index.php?page=add_record&id=${zoneId}`);

    // Select PTR record type
    await page.locator('select[name*="type"]').first().selectOption('PTR');

    // Fill in the PTR record name (nibbles)
    await page.locator('input[name*="name"]').first().fill(ptrNibbles);

    // Fill in the PTR content (hostname)
    await page.locator('input[name*="content"], input[name*="value"]').first().fill(ptrContent);

    // Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for page to load
    await page.waitForLoadState('networkidle');

    // Check for errors
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify we're redirected to edit page (success)
    const url = page.url();
    expect(url).toContain('page=edit');
  });

  test('should verify PTR record name contains user input (issue #959 bug check)', async ({ adminPage: page }) => {
    // Navigate directly to zone edit page
    await page.goto('/index.php?page=list_reverse_zones');

    const zoneRow = page.locator('tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() === 0) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found`);
      return;
    }

    // Get zone ID and navigate directly to edit page
    const editLink = zoneRow.locator('a[href*="page=edit"]').first();
    const href = await editLink.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    const localZoneId = match ? match[1] : null;

    expect(localZoneId, 'Zone ID should be found').toBeTruthy();

    // Navigate directly to the zone edit page
    await page.goto(`/index.php?page=edit&id=${localZoneId}`);
    await page.waitForLoadState('networkidle');

    // Look for PTR record by checking input values (records are in form inputs)
    const contentInputs = await page.locator('input[name*="content"]').all();
    let foundRecord = false;
    let recordName = '';

    for (const input of contentInputs) {
      const value = await input.inputValue();
      if (value.includes('ipv6-ptr-test')) {
        foundRecord = true;
        // Get the corresponding name input from the same row
        const row = input.locator('xpath=ancestor::tr');
        const nameInput = row.locator('input[name*="name"]').first();
        if (await nameInput.count() > 0) {
          recordName = await nameInput.inputValue();
        }
        break;
      }
    }

    if (!foundRecord) {
      test.skip(true, 'PTR record not found - previous test may have failed');
      return;
    }

    // BUG CHECK: The record name should NOT be just the zone name
    // It should contain the nibbles the user entered
    expect(recordName, 'Record name should not be empty').not.toBe('');

    // The record name should contain at least part of our nibble input (1.2.3.4.5.6.7.8)
    const containsUserInput = recordName.includes('1.2.3.4.5.6.7.8');
    expect(containsUserInput, `Record name "${recordName}" should contain user input nibbles`).toBe(true);
  });

  test('should edit PTR record and preserve name correctly', async ({ adminPage: page }) => {
    // Navigate to the zone edit page
    await page.goto('/index.php?page=list_reverse_zones');

    const zoneRow = page.locator('tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() === 0) {
      test.skip(true, `IPv6 zone ${ipv6Zone} not found`);
      return;
    }

    // Get zone ID and navigate directly
    const editLink = zoneRow.locator('a[href*="page=edit"]').first();
    const href = await editLink.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    const localZoneId = match ? match[1] : null;

    await page.goto(`/index.php?page=edit&id=${localZoneId}`);
    await page.waitForLoadState('networkidle');

    // Find our PTR record by checking input values
    const contentInputs = await page.locator('input[name*="content"]').all();
    let recordContentInput = null;
    let recordNameInput = null;
    let originalName = '';

    for (const input of contentInputs) {
      const value = await input.inputValue();
      if (value.includes('ipv6-ptr-test')) {
        recordContentInput = input;
        // Get the corresponding name input from the same row
        const row = input.locator('xpath=ancestor::tr');
        const nameInput = row.locator('input[name*="name"]').first();
        if (await nameInput.count() > 0) {
          recordNameInput = nameInput;
          originalName = await nameInput.inputValue();
        }
        break;
      }
    }

    if (!recordContentInput) {
      test.skip(true, 'PTR record not found');
      return;
    }

    // Update the content slightly
    const newContent = 'updated-ipv6-ptr-test.example.com';
    await recordContentInput.fill(newContent);

    // Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify the name was preserved after edit - navigate back to zone
    await page.goto(`/index.php?page=edit&id=${localZoneId}`);
    await page.waitForLoadState('networkidle');

    // Find the updated record and check name is preserved
    const updatedInputs = await page.locator('input[name*="content"]').all();
    for (const input of updatedInputs) {
      const value = await input.inputValue();
      if (value.includes('updated-ipv6-ptr-test')) {
        const row = input.locator('xpath=ancestor::tr');
        const nameInput = row.locator('input[name*="name"]').first();
        if (await nameInput.count() > 0) {
          const updatedName = await nameInput.inputValue();
          // Name should be preserved after edit
          expect(updatedName, 'Record name should be preserved after edit').toBe(originalName);
        }
        break;
      }
    }
  });

  test.afterAll(async ({ browser }) => {
    // Cleanup: delete the test PTR records we created
    const page = await browser.newPage();
    const { loginAndWaitForDashboard } = await import('../../helpers/auth.js');
    const users = await import('../../fixtures/users.json', { with: { type: 'json' } });
    const userData = users.default;

    await loginAndWaitForDashboard(page, userData.admin.username, userData.admin.password);
    await page.goto('/index.php?page=list_reverse_zones');

    const zoneRow = page.locator('tr').filter({ hasText: ipv6Zone });
    if (await zoneRow.count() > 0) {
      const editLink = zoneRow.locator('a[href*="page=edit"]').first();
      const href = await editLink.getAttribute('href');
      const match = href?.match(/id=(\d+)/);
      const localZoneId = match ? match[1] : null;

      if (localZoneId) {
        await page.goto(`/index.php?page=edit&id=${localZoneId}`);
        await page.waitForLoadState('networkidle');

        // Find and delete records containing 'ipv6-ptr-test'
        const contentInputs = await page.locator('input[name*="content"]').all();
        for (const input of contentInputs) {
          const value = await input.inputValue();
          if (value.includes('ipv6-ptr-test')) {
            const row = input.locator('xpath=ancestor::tr');
            const deleteLink = row.locator('a[href*="page=delete_record"]');
            if (await deleteLink.count() > 0) {
              await deleteLink.first().click();
              await page.waitForLoadState('networkidle');
              const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
              if (await confirmButton.count() > 0) {
                await confirmButton.first().click();
                await page.waitForLoadState('networkidle');
              }
              // Navigate back to zone edit page for next iteration
              await page.goto(`/index.php?page=edit&id=${localZoneId}`);
              await page.waitForLoadState('networkidle');
              break; // Only delete one at a time, loop will re-scan
            }
          }
        }
      }
    }

    await page.close();
  });
});
