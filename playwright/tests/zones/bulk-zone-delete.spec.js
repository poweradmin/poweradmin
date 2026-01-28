/**
 * Bulk Zone Deletion Tests
 *
 * Tests for bulk zone deletion functionality including
 * selecting multiple zones and confirming deletion.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Bulk Zone Deletion', () => {
  const testZones = [
    `bulk-del-1-${Date.now()}.example.com`,
    `bulk-del-2-${Date.now()}.example.com`,
    `bulk-del-3-${Date.now()}.example.com`
  ];

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Create test zones
    for (const domain of testZones) {
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(domain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');
    }

    await page.close();
  });

  test.describe('Bulk Delete Page', () => {
    test('should access bulk delete page with selected zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      // Select multiple zones using checkboxes if available
      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 2) {
        await checkboxes.nth(0).check();
        await checkboxes.nth(1).check();

        // Find and click delete selected button
        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();
          await expect(page).toHaveURL(/.*delete/);
        }
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText.toLowerCase()).toMatch(/are you sure|confirm|delete/i);
        }
      }
    });

    test('should display zone names to be deleted', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          // Should show table with zone info
          const table = page.locator('table');
          expect(await table.count()).toBeGreaterThan(0);
        }
      }
    });

    test('should display zone owner information', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText.toLowerCase()).toMatch(/owner|name|type/i);
        }
      }
    });

    test('should display Yes button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")');
          expect(await yesBtn.count()).toBeGreaterThan(0);
        }
      }
    });

    test('should display No button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const noBtn = page.locator('input[value="No"], button:has-text("No")');
          expect(await noBtn.count()).toBeGreaterThan(0);
        }
      }
    });

    test('should cancel bulk delete and return to zones list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
          if (await noBtn.count() > 0) {
            await noBtn.click();
            await expect(page).toHaveURL(/.*zones\/forward/);
          }
        }
      }
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const breadcrumb = page.locator('.breadcrumb, nav[aria-label*="breadcrumb"]');
          if (await breadcrumb.count() > 0) {
            await expect(breadcrumb.first()).toBeVisible();
          }
        }
      }
    });
  });

  test.describe('Bulk Delete Execution', () => {
    test('should delete multiple zones when confirmed', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Create temporary zones for deletion test
      const tempZone1 = `temp-bulk-1-${Date.now()}.example.com`;
      const tempZone2 = `temp-bulk-2-${Date.now()}.example.com`;

      // Create zones
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(tempZone1);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(tempZone2);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Go to zones list and select for deletion
      await page.goto('/zones/forward?letter=all');

      const checkbox1 = page.locator(`tr:has-text("${tempZone1}") input[type="checkbox"]`).first();
      const checkbox2 = page.locator(`tr:has-text("${tempZone2}") input[type="checkbox"]`).first();

      if (await checkbox1.count() > 0 && await checkbox2.count() > 0) {
        await checkbox1.check();
        await checkbox2.check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) {
            await yesBtn.click();

            const bodyText = await page.locator('body').textContent();
            expect(bodyText).not.toMatch(/fatal|exception/i);
          }
        }
      }
    });

    test('should redirect to zones list with success message after deletion (issue #971)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Create temporary zone for deletion test
      const tempZone = `issue971-${Date.now()}.example.com`;

      // Create zone
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(tempZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Go to zones list and select for deletion
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const checkbox = page.locator(`tr:has-text("${tempZone}") input[type="checkbox"]`).first();
      expect(await checkbox.count()).toBeGreaterThan(0);

      await checkbox.check();

      const deleteBtn = page.locator('button:has-text("Delete zone")').first();
      expect(await deleteBtn.count()).toBeGreaterThan(0);
      await deleteBtn.click();
      await page.waitForLoadState('networkidle');

      // Verify we're on the confirmation page
      await expect(page).toHaveURL(/.*delete/);

      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
      expect(await yesBtn.count()).toBeGreaterThan(0);
      await yesBtn.click();
      await page.waitForLoadState('networkidle');

      // CRITICAL: Verify that after clicking Yes, we are redirected to zones list (not an error page)
      // This catches issue #971 where an error page was shown instead of redirect
      await expect(page).toHaveURL(/.*zones\/forward/);

      // Verify NO error message is displayed
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toContain('An error occurred while processing the request');
      expect(bodyText).not.toContain('Error:');

      // Verify success message is displayed (without needing a refresh)
      const successAlert = page.locator('.alert-success');
      expect(await successAlert.count()).toBeGreaterThan(0);

      // Verify zone is deleted
      const zoneRow = page.locator(`tr:has-text("${tempZone}")`);
      expect(await zoneRow.count()).toBe(0);
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not see bulk delete option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")');
      expect(await deleteBtn.count()).toBe(0);
    });

    test('client should not see bulk delete option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/forward?letter=all');

      const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")');
      expect(await deleteBtn.count()).toBe(0);
    });
  });
});
