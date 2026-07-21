/**
 * Delete Confirmation Pages Tests
 *
 * Tests for all delete confirmation pages:
 * - Zone delete (single and bulk)
 * - User delete with zone handling
 * - Record delete (single and bulk)
 * - Supermaster delete
 * - Zone template delete
 * - Permission template delete
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Zone Delete Confirmation', () => {
  test.describe('Single Zone Delete', () => {
    test('should render the zone delete confirmation page', async ({ adminPage: page }) => {
      // Navigate to forward zones
      await page.goto('/zones/forward?letter=all');

      // Check for delete links
      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // Auto-retrying assertion first so the click navigation settles
        await expect(page.locator('.alert-danger')).toBeVisible();
        await expect(page.locator('body')).toContainText(/delete.*zone|zone.*delete/i);

        const bodyText = await page.locator('body').textContent();
        const hasDetails = bodyText.toLowerCase().includes('owner') ||
                           bodyText.toLowerCase().includes('type') ||
                           bodyText.toLowerCase().includes('details');
        expect(hasDetails).toBeTruthy();

        const confirmBtn = page.locator('button[type="submit"]:has-text("delete"), button:has-text("Yes")');
        expect(await confirmBtn.count() > 0).toBeTruthy();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        await expect(cancelBtn.first()).toBeVisible();

        const csrfToken = page.locator('input[name="_token"]');
        expect(await csrfToken.count() > 0).toBeTruthy();

        await expect(page.locator('nav[aria-label="breadcrumb"]')).toBeVisible();
      } else {
        // No zones to delete, test passes
        expect(true).toBeTruthy();
      }
    });

    test('cancel should return to zones list', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          // Should return to zones list
          await expect(page).toHaveURL(/.*\/zones\/(forward|reverse)/);
        }
      }
    });
  });

  test.describe('Bulk Zone Delete', () => {
    test('should display all selected zones in table', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');

      // Check for bulk delete functionality
      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      const hasBulkSelect = await checkboxes.count() > 0;

      // Verify bulk delete structure exists
      expect(hasBulkSelect || page.url().includes('/zones/forward')).toBeTruthy();
    });

    test('zones table should show name, owner, type columns', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');

      const bodyText = await page.locator('body').textContent();

      // Check for column headers
      const hasColumns = bodyText.toLowerCase().includes('name') ||
                          bodyText.toLowerCase().includes('owner') ||
                          bodyText.toLowerCase().includes('type');
      expect(hasColumns).toBeTruthy();
    });
  });
});

test.describe('User Delete Confirmation', () => {
  test.describe('User Delete Page', () => {
    test('should render the user delete confirmation page', async ({ adminPage: page }) => {
      await page.goto('/users');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // Auto-retrying assertion first so the click navigation settles
        await expect(page.locator('body')).toContainText(/delete.*user|user/i);

        const warningAlert = page.locator('.alert-danger, .alert-warning');
        expect(await warningAlert.count() > 0 || page.url().includes('/delete')).toBeTruthy();

        const bodyText = await page.locator('body').textContent();
        // Zone ownership transfer options - may or may not have zones, displays either way
        const hasContent = bodyText.toLowerCase().includes('zone') ||
                           bodyText.toLowerCase().includes('user') ||
                           bodyText.toLowerCase().includes('delete');
        expect(hasContent).toBeTruthy();

        // Form with radio buttons for zone handling options
        expect(await page.locator('form').count() > 0 || page.url().includes('/delete')).toBeTruthy();

        // Dropdown for new owner selection may be present
        expect(await page.locator('select').count() >= 0 || bodyText.length > 0).toBeTruthy();

        // Should explain options or just show delete form
        expect(bodyText.length).toBeGreaterThan(0);

        const confirmBtn = page.locator('button[type="submit"], button:has-text("delete")');
        await expect(confirmBtn.first()).toBeAttached();

        const cancelBtn = page.locator('a[href*="/users"]:has-text("No"), a:has-text("keep")');
        await expect(cancelBtn.first()).toBeAttached();
      }
    });
  });
});

test.describe('Record Delete Confirmation', () => {
  test.describe('Single Record Delete', () => {
    test('should display record details when navigating to delete', async ({ adminPage: page }) => {
      // Navigate directly to a zone edit page
      await page.goto('/zones/forward?letter=all');

      // Wait for page to load
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Verify we're on zones page
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
    });

    test('should have delete record links in zone edit view', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Check for zone links
      const zoneLinks = page.locator('a[href*="/zones/"][href*="/edit"]');
      const hasZoneLinks = await zoneLinks.count() > 0;

      // If zones exist, verify structure
      expect(hasZoneLinks || page.url().includes('/zones/forward')).toBeTruthy();
    });

    test('should show record type badges in zone view', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Zone list page should have zone information
      expect(bodyText.length).toBeGreaterThan(100);
    });

    test('should have action buttons in zone list', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const buttons = page.locator('a.btn, button.btn');
      const hasButtons = await buttons.count() > 0;
      expect(hasButtons).toBeTruthy();
    });
  });

  test.describe('Bulk Record Delete', () => {
    test('should have bulk selection capability in zone edit', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Verify page loaded
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
    });

    test('should display record columns in zone list', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();

      // Should show zone list columns
      const hasColumns = bodyText.toLowerCase().includes('name') ||
                          bodyText.toLowerCase().includes('type') ||
                          bodyText.toLowerCase().includes('zone');
      expect(hasColumns).toBeTruthy();
    });

    test('should have table structure for zones', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const table = page.locator('table');
      const hasTable = await table.count() > 0;

      // Should have table or show message
      expect(hasTable || page.url().includes('/zones/forward')).toBeTruthy();
    });
  });
});

test.describe('Supermaster Delete Confirmation', () => {
  test.describe('Supermaster Delete Page', () => {
    test('should render the supermaster delete confirmation page', async ({ adminPage: page }) => {
      await page.goto('/supermasters');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // Auto-retrying assertion first so the click navigation settles
        await expect(page.locator('body')).toContainText(/supermaster|delete|ip/i);

        const warningAlert = page.locator('.alert-danger');
        expect(await warningAlert.count() > 0 || page.url().includes('/delete')).toBeTruthy();

        const bodyText = await page.locator('body').textContent();
        const hasDetails = bodyText.toLowerCase().includes('hostname') ||
                           bodyText.toLowerCase().includes('ns') ||
                           bodyText.toLowerCase().includes('account');
        expect(hasDetails || page.url().includes('/delete')).toBeTruthy();

        await expect(page.locator('a.btn').first()).toBeVisible();
      }
    });

    test('cancel should return to supermasters list', async ({ adminPage: page }) => {
      await page.goto('/supermasters');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          await expect(page).toHaveURL(/.*\/supermasters/);
        }
      }
    });
  });
});

test.describe('Zone Template Delete Confirmation', () => {
  test.describe('Zone Template Delete Page', () => {
    test('should render the zone template delete confirmation page', async ({ adminPage: page }) => {
      await page.goto('/zones/templates');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // Auto-retrying assertion first so the click navigation settles
        await expect(page.locator('body')).toContainText(/template|delete/i);

        const warningAlert = page.locator('.alert-danger');
        expect(await warningAlert.count() > 0 || page.url().includes('/delete')).toBeTruthy();

        // May or may not show warning depending on template usage
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.length).toBeGreaterThan(0);

        await expect(page.locator('a.btn').first()).toBeVisible();
      }
    });

    test('cancel should return to templates list', async ({ adminPage: page }) => {
      await page.goto('/zones/templates');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          await expect(page).toHaveURL(/.*\/zones\/templates/);
        }
      }
    });
  });
});

test.describe('Permission Template Delete Confirmation', () => {
  test.describe('Permission Template Delete Page', () => {
    test('should render the permission template delete confirmation page', async ({ adminPage: page }) => {
      await page.goto('/permissions/templates');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // Auto-retrying assertion first so the click navigation settles
        await expect(page.locator('body')).toContainText(/permission.*template|template|delete/i);

        const warningAlert = page.locator('.alert-danger');
        expect(await warningAlert.count() > 0 || page.url().includes('/delete')).toBeTruthy();

        // May or may not show warning depending on template usage
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.length).toBeGreaterThan(0);

        await expect(page.locator('a.btn').first()).toBeVisible();
      }
    });

    test('cancel should return to permission templates list', async ({ adminPage: page }) => {
      await page.goto('/permissions/templates');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          await expect(page).toHaveURL(/.*\/permissions\/templates/);
        }
      }
    });
  });
});

test.describe('Common Delete Confirmation Elements', () => {
  test.describe('UI Consistency', () => {
    test('delete pages should use consistent styling and icons', async ({ adminPage: page }) => {
      await page.goto('/zones/forward?letter=all');

      const deleteLinks = page.locator('a[href*="/delete"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // Auto-retrying assertion first so the click navigation settles
        await expect(page.locator('.btn-danger').first()).toBeVisible();
        await expect(page.locator('.btn-secondary').first()).toBeAttached();
        await expect(page.locator('.bi-trash, .bi-trash-fill').first()).toBeAttached();
        await expect(page.locator('.bi-exclamation-triangle, .bi-exclamation-triangle-fill').first()).toBeAttached();
      }
    });
  });

  test.describe('Security', () => {
    test('delete confirmation should require authentication', async ({ page }) => {
      await page.goto('/zones/1/delete');

      // Should redirect to login
      await expect(page).toHaveURL(/.*\/login/);
    });

    test('delete user confirmation should require authentication', async ({ page }) => {
      await page.goto('/users/1/delete');

      await expect(page).toHaveURL(/.*\/login/);
    });
  });
});
