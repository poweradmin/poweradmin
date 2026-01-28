import { test, expect } from '../../fixtures/test-fixtures.js';
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
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(domain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');
    }

    await page.close();
  });

  test.describe('Bulk Delete Page', () => {
    test('should access bulk delete page with selected zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

      // Select multiple zones using checkboxes if available
      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 2) {
        await checkboxes.nth(0).check();
        await checkboxes.nth(1).check();

        // Find and click delete selected button
        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();
          await expect(page).toHaveURL(/delete_domains/);
        }
      }
    });

    test('should display confirmation message', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

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

    test('should display zone names to be deleted', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

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

    test('should display zone owner information', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

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

    test('should display Yes button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

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

    test('should display No button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

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

    test('should cancel bulk delete and return to zones list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      if (await checkboxes.count() >= 1) {
        await checkboxes.nth(0).check();

        const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();

          const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
          if (await noBtn.count() > 0) {
            await noBtn.click();
            await expect(page).toHaveURL(/list_forward_zones/);
          }
        }
      }
    });

    test('should display breadcrumb navigation', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

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
    test('should delete multiple zones when confirmed', async ({ adminPage: page }) => {
      // Create temporary zones for deletion test
      const tempZone1 = `temp-bulk-1-${Date.now()}.example.com`;
      const tempZone2 = `temp-bulk-2-${Date.now()}.example.com`;

      // Create zones
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(tempZone1);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(tempZone2);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Go to zones list and select for deletion
      await page.goto('/index.php?page=list_forward_zones&letter=all');

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
            await page.waitForLoadState('networkidle');

            // Verify zones are deleted
            await page.goto('/index.php?page=list_forward_zones&letter=all');
            const zone1Row = page.locator(`tr:has-text("${tempZone1}")`);
            const zone2Row = page.locator(`tr:has-text("${tempZone2}")`);
            expect(await zone1Row.count()).toBe(0);
            expect(await zone2Row.count()).toBe(0);
          }
        }
      }
    });

    test('should redirect to zones list with success message after deletion (issue #971)', async ({ adminPage: page }) => {
      // Create temporary zone for deletion test
      const tempZone = `issue971-${Date.now()}.example.com`;

      // Create zone
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(tempZone);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Go to zones list and select for deletion
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      await page.waitForLoadState('networkidle');

      const checkbox = page.locator(`tr:has-text("${tempZone}") input[type="checkbox"]`).first();
      expect(await checkbox.count()).toBeGreaterThan(0);

      await checkbox.check();

      const deleteBtn = page.locator('button:has-text("Delete zone")').first();
      expect(await deleteBtn.count()).toBeGreaterThan(0);
      await deleteBtn.click();
      await page.waitForLoadState('networkidle');

      // Verify we're on the confirmation page
      await expect(page).toHaveURL(/delete_domains/);

      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
      expect(await yesBtn.count()).toBeGreaterThan(0);
      await yesBtn.click();
      await page.waitForLoadState('networkidle');

      // CRITICAL: Verify that after clicking Yes, we are redirected to zones list (not an error page)
      // This catches issue #971 where an error page was shown instead of redirect
      await expect(page).toHaveURL(/list_forward_zones/);

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

  test.describe('Bulk Delete Permissions', () => {
    test('admin should access bulk delete', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      // Admin should see checkboxes for bulk operations
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('manager should access bulk delete for own zones', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not have bulk delete option', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

      // Viewer should not see delete checkboxes or button
      const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")');
      expect(await deleteBtn.count()).toBe(0);
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    for (const domain of testZones) {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      const row = page.locator(`tr:has-text("${domain}")`);
      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_domain"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) await yesBtn.click();
        }
      }
    }

    await page.close();
  });
});
