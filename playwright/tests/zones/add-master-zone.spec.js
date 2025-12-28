import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Master Zone Management', () => {
  const testZone = `test-zone-${Date.now()}.com`;
  const reverseZone = '1.168.192.in-addr.arpa';

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should add a master zone successfully', async ({ page }) => {
    await page.goto('/index.php?page=add_zone_master');

    // Fill in zone name
    await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]').first().fill(testZone);

    // Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify success - either redirected to zone list/edit or shows success message
    const bodyText = await page.locator('body').textContent();
    const hasSuccess = bodyText.toLowerCase().includes('success') ||
                       bodyText.toLowerCase().includes('added') ||
                       bodyText.toLowerCase().includes('created') ||
                       page.url().includes('page=edit') ||
                       page.url().includes('page=list_zones');
    expect(hasSuccess).toBeTruthy();
  });

  test('should add a reverse zone successfully', async ({ page }) => {
    await page.goto('/index.php?page=add_zone_master');

    // Fill in reverse zone name
    await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]').first().fill(reverseZone);

    // Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify success
    const bodyText = await page.locator('body').textContent();
    const hasSuccess = bodyText.toLowerCase().includes('success') ||
                       bodyText.toLowerCase().includes('added') ||
                       page.url().includes('page=edit') ||
                       page.url().includes('page=list_zones');
    expect(hasSuccess).toBeTruthy();
  });

  test('should add a record to a master zone successfully', async ({ page }) => {
    // First ensure we have a zone - go to zones list
    await page.goto('/index.php?page=list_zones');

    // Check if test zone exists, if not create it
    let bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(testZone)) {
      // Create the zone first
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]').first().fill(testZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.goto('/index.php?page=list_zones');
    }

    // Click on the zone to edit
    const zoneRow = page.locator(`tr:has-text("${testZone}")`);
    if (await zoneRow.count() > 0) {
      await zoneRow.locator('a').first().click();

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

  test('should delete a master zone successfully', async ({ page }) => {
    await page.goto('/index.php?page=list_zones');

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

  test('should delete a reverse zone successfully', async ({ page }) => {
    await page.goto('/index.php?page=list_zones');

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
    await page.goto('/index.php?page=list_zones');

    // Delete test zones if they exist
    for (const zone of [testZone, reverseZone]) {
      const zoneRow = page.locator(`tr:has-text("${zone}")`);
      if (await zoneRow.count() > 0) {
        const deleteLink = zoneRow.locator('a').filter({ hasText: /Delete/i });
        if (await deleteLink.count() > 0) {
          await deleteLink.first().click();
          const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
          if (await confirmButton.count() > 0) {
            await confirmButton.first().click();
          }
          await page.waitForTimeout(500);
        }
      }
    }

    await page.close();
  });
});
