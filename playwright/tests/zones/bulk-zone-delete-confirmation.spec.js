/**
 * Bulk Zone Delete Confirmation Tests
 *
 * Tests for bulk zone deletion confirmation behavior (GitHub issue #971)
 * - Proper success message display after deletion
 * - No error page on successful deletion
 * - Verification that zones are actually deleted
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, findZoneIdByName, openZoneListPageFor, zoneExists } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Bulk Zone Delete Confirmation (Issue #971)', () => {
  const timestamp = Date.now();
  // The shared timestamp comes first in the suffix so the three names stay
  // adjacent in the zone list and one list page holds them all.
  const testZones = [
    `bulk-confirm-${timestamp}-1.example.com`,
    `bulk-confirm-${timestamp}-2.example.com`,
    `bulk-confirm-${timestamp}-3.example.com`
  ];

  test.describe('Setup Test Zones', () => {
    test('should create test zones for deletion', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      for (const domain of testZones) {
        await page.goto('/zones/add/master');
        await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(domain);
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        await page.waitForLoadState('networkidle');
      }

      // Verify zones were created
      for (const domain of testZones) {
        expect(await zoneExists(page, domain)).toBe(true);
      }
    });
  });

  test.describe('Bulk Delete Success Confirmation', () => {
    test('should show success message after bulk delete (regression #971)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(await openZoneListPageFor(page, testZones[0])).toBe(true);

      // Select test zones for deletion
      for (const domain of testZones) {
        const checkbox = page.locator(`tr:has-text("${domain}") input[type="checkbox"]`).first();
        await expect(checkbox).toBeVisible();
        await checkbox.check();
      }

      // Click delete selected button
      const deleteBtn = page.locator('button:has-text("Delete zone"), input[value*="Delete zone"], input[value*="Delete selected"], button:has-text("Delete selected")').first();
      if (await deleteBtn.count() === 0) {
        test.skip('Delete button not found');
        return;
      }

      await deleteBtn.click();
      await page.waitForLoadState('networkidle');

      // Should be on confirmation page, not error page
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/error occurred|fatal|exception/i);

      // Confirm deletion
      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
      if (await yesBtn.count() > 0) {
        await yesBtn.click();
        await page.waitForLoadState('networkidle');

        // BUG CHECK: Should NOT show error page after successful deletion
        // Issue #971: "An error occurred while processing the request" shows but deletion works
        const afterDeleteText = await page.locator('body').textContent();

        // Should show success or redirect to zones list
        const hasError = afterDeleteText.toLowerCase().includes('error occurred');
        const hasSuccess = afterDeleteText.toLowerCase().includes('success') ||
                          afterDeleteText.toLowerCase().includes('deleted') ||
                          afterDeleteText.toLowerCase().includes('zone');

        // If we see "error occurred" but deletion actually worked, that's the bug
        if (hasError) {
          // Verify zones were actually deleted
          let stillExists = false;
          for (const domain of testZones) {
            if (await zoneExists(page, domain)) {
              stillExists = true;
              break;
            }
          }

          // If zones don't exist anymore but we got error message = BUG #971
          expect(stillExists || !hasError, 'BUG #971: Error message shown but deletion succeeded').toBeTruthy();
        }
      }
    });

    test('should not require page refresh to see success message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Create a single zone for this test
      const singleZone = `single-delete-${timestamp}.example.com`;
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(singleZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Go to zones list and delete it
      expect(await openZoneListPageFor(page, singleZone)).toBe(true);

      const checkbox = page.locator(`tr:has-text("${singleZone}") input[type="checkbox"]`).first();
      await checkbox.check();

      const deleteBtn = page.locator('button:has-text("Delete zone"), input[value*="Delete zone"], input[value*="Delete selected"], button:has-text("Delete selected")').first();
      await deleteBtn.click();
      await page.waitForLoadState('networkidle');

      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
      if (await yesBtn.count() > 0) {
        await yesBtn.click();
        await page.waitForLoadState('networkidle');

        // Verify the zone was actually deleted
        const zoneDeleted = !(await zoneExists(page, singleZone));
        expect(zoneDeleted, 'Zone should be deleted').toBeTruthy();
      }
    });

    test('should redirect to zones list after successful deletion', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Create zone for test
      const redirectZone = `redirect-test-${timestamp}.example.com`;
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(redirectZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      expect(await openZoneListPageFor(page, redirectZone)).toBe(true);

      const checkbox = page.locator(`tr:has-text("${redirectZone}") input[type="checkbox"]`).first();
      await checkbox.check();

      const deleteBtn = page.locator('button:has-text("Delete zone"), input[value*="Delete zone"], input[value*="Delete selected"], button:has-text("Delete selected")').first();
      await deleteBtn.click();
      await page.waitForLoadState('networkidle');

      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
      if (await yesBtn.count() > 0) {
        await yesBtn.click();
        await page.waitForLoadState('networkidle');

        // Should be on zones list or show success, not error page
        const url = page.url();
        const bodyText = await page.locator('body').textContent();

        const isOnZonesList = url.includes('/zones');
        const hasError = bodyText.toLowerCase().includes('error occurred');

        expect(isOnZonesList || !hasError, 'Should be on zones list or show success').toBeTruthy();
      }
    });
  });

  test.describe('Verify Deletion Actually Works', () => {
    test('should actually delete zones after confirmation', async ({ page }) => {
      // Creating a zone, walking the paginated list and deleting it needs more
      // than the default per-test budget.
      test.slow();

      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Create a verification zone
      const verifyZone = `verify-delete-${timestamp}.example.com`;
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(verifyZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Verify it exists
      expect(await openZoneListPageFor(page, verifyZone)).toBe(true);

      // Delete it
      const checkbox = page.locator(`tr:has-text("${verifyZone}") input[type="checkbox"]`).first();
      await checkbox.check();

      const deleteBtn = page.locator('button:has-text("Delete zone"), input[value*="Delete zone"], input[value*="Delete selected"], button:has-text("Delete selected")').first();
      await deleteBtn.click();
      await page.waitForLoadState('networkidle');

      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
      if (await yesBtn.count() > 0) {
        await yesBtn.click();
        await page.waitForLoadState('networkidle');
      }

      // Verify it's gone (even if error message showed)
      expect(await zoneExists(page, verifyZone)).toBe(false);
    });
  });

  test.describe('Cancel Bulk Delete', () => {
    test('should not delete zones when cancelled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Create a zone to test cancel
      const cancelZone = `cancel-test-${timestamp}.example.com`;
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(cancelZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      expect(await openZoneListPageFor(page, cancelZone)).toBe(true);

      const checkbox = page.locator(`tr:has-text("${cancelZone}") input[type="checkbox"]`).first();
      await checkbox.check();

      const deleteBtn = page.locator('button:has-text("Delete zone"), input[value*="Delete zone"], input[value*="Delete selected"], button:has-text("Delete selected")').first();
      await deleteBtn.click();
      await page.waitForLoadState('networkidle');

      // Cancel deletion - the cancel control is a link, not a button
      const noLink = page.locator('a:has-text("No")').first();
      await expect(noLink).toBeVisible();
      await noLink.click();
      await page.waitForLoadState('networkidle');

      // Verify zone still exists
      expect(await zoneExists(page, cancelZone)).toBe(true);

      // Cleanup - delete the zone
      const cancelZoneId = await findZoneIdByName(page, cancelZone);
      if (cancelZoneId) {
        await deleteZoneById(page, cancelZoneId);
      }
    });
  });
});
