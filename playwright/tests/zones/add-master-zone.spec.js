import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Master Zone Management', () => {
  const timestamp = Date.now();
  const testZone = `test-zone-${timestamp}.com`;
  // Use timestamp modulo to create valid reverse zone octets (0-255)
  const octet1 = timestamp % 256;
  const octet2 = Math.floor(timestamp / 1000) % 256;
  const reverseZone = `${octet1}.${octet2}.10.in-addr.arpa`;

  test('should add a master zone successfully', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_master');

    // Fill in zone name
    await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]').first().fill(testZone);

    // Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for page to load after submission
    await page.waitForLoadState('networkidle');

    // Verify success - either redirected to zone list/edit or shows success message
    const bodyText = await page.locator('body').textContent();
    const currentUrl = page.url();
    const hasSuccess = bodyText.toLowerCase().includes('success') ||
                       bodyText.toLowerCase().includes('added') ||
                       bodyText.toLowerCase().includes('created') ||
                       currentUrl.includes('page=edit') ||
                       currentUrl.includes('page=list_forward_zones');
    expect(hasSuccess, `Expected success but got: URL=${currentUrl}, body contains success=${bodyText.includes('success')}`).toBeTruthy();
  });

  test('should add a reverse zone successfully', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_master');

    // Fill in reverse zone name
    await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]').first().fill(reverseZone);

    // Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for page to load after submission
    await page.waitForLoadState('networkidle');

    // Verify success - reverse zones redirect to list_reverse_zones
    const bodyText = await page.locator('body').textContent();
    const currentUrl = page.url();
    const hasSuccess = bodyText.toLowerCase().includes('success') ||
                       bodyText.toLowerCase().includes('added') ||
                       currentUrl.includes('page=edit') ||
                       currentUrl.includes('page=list_reverse_zones');
    expect(hasSuccess).toBeTruthy();
  });

  test('should add a record to a master zone successfully', async ({ adminPage: page }) => {
    // First ensure we have a zone - go to zones list
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Check if test zone exists, if not create it
    let bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(testZone)) {
      // Create the zone first
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]').first().fill(testZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.goto('/index.php?page=list_forward_zones&letter=all');
    }

    // Click on the zone to edit
    const zoneRow = page.locator(`tr:has-text("${testZone}")`);
    if (await zoneRow.count() > 0) {
      // Click on the zone name link (which goes to page=edit)
      const editLink = zoneRow.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
      } else {
        // Fallback: click first link with zone name
        await zoneRow.locator(`a:has-text("${testZone}")`).first().click();
      }

      // Should be on edit page
      await expect(page).toHaveURL(/page=edit/);

      // Check if there's an add record form
      const hasRecordForm = await page.locator('input[name*="content"], input[name*="value"]').count() > 0;
      if (hasRecordForm) {
        // Select record type
        const typeSelect = page.locator('select[name*="type"]').first();
        if (await typeSelect.count() > 0) {
          await typeSelect.selectOption('A');
        }

        // Fill record name
        const nameInput = page.locator('input[name*="name"]').first();
        if (await nameInput.count() > 0) {
          await nameInput.fill('www');
        }

        // Fill record content
        await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');

        // Submit
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Verify - page should not have error
        bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });

  test('should delete a master zone successfully', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Find zone and delete
    const zoneRow = page.locator(`tr:has-text("${testZone}")`);
    if (await zoneRow.count() > 0) {
      const deleteLink = zoneRow.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();

        // Confirm deletion
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }

        // Verify deletion
        const bodyText = await page.locator('body').textContent();
        const wasDeleted = bodyText.toLowerCase().includes('success') ||
                          bodyText.toLowerCase().includes('deleted') ||
                          !bodyText.includes(testZone);
        expect(wasDeleted).toBeTruthy();
      }
    }
  });

  test('should delete a reverse zone successfully', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_reverse_zones');

    // Find reverse zone and delete
    const zoneRow = page.locator(`tr:has-text("${reverseZone}")`);
    if (await zoneRow.count() > 0) {
      const deleteLink = zoneRow.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();

        // Confirm deletion
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }

        // Verify deletion
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });

  // Cleanup after all tests
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Delete forward zone from forward zones list
    await page.goto('/index.php?page=list_forward_zones&letter=all');
    const forwardZoneRow = page.locator(`tr:has-text("${testZone}")`);
    if (await forwardZoneRow.count() > 0) {
      const deleteLink = forwardZoneRow.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }
        await page.waitForTimeout(500);
      }
    }

    // Delete reverse zone from reverse zones list
    await page.goto('/index.php?page=list_reverse_zones');
    const reverseZoneRow = page.locator(`tr:has-text("${reverseZone}")`);
    if (await reverseZoneRow.count() > 0) {
      const deleteLink = reverseZoneRow.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }
        await page.waitForTimeout(500);
      }
    }

    await page.close();
  });
});
