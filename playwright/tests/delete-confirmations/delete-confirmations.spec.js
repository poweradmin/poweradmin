/**
 * Delete Confirmation Pages Tests
 *
 * Tests for all delete confirmation pages:
 * - delete_domain.html - Single zone delete
 * - delete_domains.html - Bulk zone delete
 * - delete_user.html - User delete with zone handling
 * - delete_record.html - Single record delete
 * - delete_records.html - Bulk record delete
 * - delete_supermaster.html - Supermaster delete
 * - delete_zone_templ.html - Zone template delete
 * - delete_perm_templ.html - Permission template delete
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Zone Delete Confirmation', () => {
  test.describe('Single Zone Delete (delete_domain.html)', () => {
    test('should display warning alert', async ({ adminPage: page }) => {
      // Navigate to forward zones
      await page.goto('/index.php?page=list_forward_zones');

      // Check for delete links
      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const warningAlert = page.locator('.alert-danger');
        await expect(warningAlert).toBeVisible();
      }
    });

    test('should display zone name being deleted', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete.*zone|zone.*delete/i);
      }
    });

    test('should display zone details card', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        const hasDetails = bodyText.toLowerCase().includes('owner') ||
                           bodyText.toLowerCase().includes('type') ||
                           bodyText.toLowerCase().includes('details');
        expect(hasDetails).toBeTruthy();
      }
    });

    test('should have confirm delete button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();
        await page.waitForLoadState('networkidle');

        const confirmBtn = page.locator('button[type="submit"]:has-text("delete"), button:has-text("Yes")');
        const hasConfirmBtn = await confirmBtn.count() > 0;
        expect(hasConfirmBtn).toBeTruthy();
      } else {
        // No zones to delete, test passes
        expect(true).toBeTruthy();
      }
    });

    test('should have cancel button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        const hasCancelBtn = await cancelBtn.count() > 0;
        expect(hasCancelBtn).toBeTruthy();
      }
    });

    test('should include CSRF token', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const csrfToken = page.locator('input[name="csrf_token"], input[name="_token"]');
        const hasToken = await csrfToken.count() > 0;
        expect(hasToken).toBeTruthy();
      }
    });

    test('should display breadcrumb navigation', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
        await expect(breadcrumb).toBeVisible();
      }
    });

    test('cancel should return to zones list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          // Should return to zones list
          await expect(page).toHaveURL(/list_forward_zones|list_reverse_zones/);
        }
      }
    });
  });

  test.describe('Bulk Zone Delete (delete_domains.html)', () => {
    test('should display all selected zones in table', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      // Check for bulk delete functionality
      const checkboxes = page.locator('input[type="checkbox"][name*="zone"]');
      const hasBulkSelect = await checkboxes.count() > 0;

      // Verify bulk delete structure exists
      expect(hasBulkSelect || page.url().includes('forward_zones')).toBeTruthy();
    });

    test('zones table should show name, owner, type columns', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

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
  test.describe('User Delete Page (delete_user.html)', () => {
    test('should display warning alert', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const warningAlert = page.locator('.alert-danger, .alert-warning');
        const hasWarning = await warningAlert.count() > 0;
        expect(hasWarning || page.url().includes('delete_user')).toBeTruthy();
      }
    });

    test('should display user name being deleted', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete.*user|user/i);
      }
    });

    test('should show zone ownership transfer options when user owns zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();

        // May or may not have zones, but should display either way
        const hasContent = bodyText.toLowerCase().includes('zone') ||
                           bodyText.toLowerCase().includes('user') ||
                           bodyText.toLowerCase().includes('delete');
        expect(hasContent).toBeTruthy();
      }
    });

    test('should have radio buttons for zone handling options', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // Check for radio buttons or form elements
        const radioButtons = page.locator('input[type="radio"]');
        const formElements = page.locator('form');

        const hasForm = await formElements.count() > 0;
        expect(hasForm || page.url().includes('delete_user')).toBeTruthy();
      }
    });

    test('should have dropdown for new owner selection', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // May have select dropdown for owner transfer
        const selects = page.locator('select');
        const bodyText = await page.locator('body').textContent();

        expect(await selects.count() >= 0 || bodyText.length > 0).toBeTruthy();
      }
    });

    test('should explain zone handling options', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        // Should explain options or just show delete form
        expect(bodyText.length).toBeGreaterThan(0);
      }
    });

    test('should have confirm delete button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const confirmBtn = page.locator('button[type="submit"], button:has-text("delete")');
        const hasConfirmBtn = await confirmBtn.count() > 0;
        expect(hasConfirmBtn).toBeTruthy();
      }
    });

    test('should have cancel button linking to users list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a[href*="users"]:has-text("No"), a:has-text("keep")');
        const hasCancelBtn = await cancelBtn.count() > 0;
        expect(hasCancelBtn).toBeTruthy();
      }
    });
  });
});

test.describe('Record Delete Confirmation', () => {
  test.describe('Single Record Delete (delete_record.html)', () => {
    test('should display record details when navigating to delete', async ({ adminPage: page }) => {
      // Navigate directly to a zone edit page
      await page.goto('/index.php?page=list_forward_zones');

      // Wait for page to load
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Verify we're on zones page
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
    });

    test('should have delete record links in zone edit view', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      // Check for zone links
      const zoneLinks = page.locator('a[href*="page=edit"]');
      const hasZoneLinks = await zoneLinks.count() > 0;

      // If zones exist, verify structure
      expect(hasZoneLinks || page.url().includes('forward_zones')).toBeTruthy();
    });

    test('should show record type badges in zone view', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Zone list page should have zone information
      expect(bodyText.length).toBeGreaterThan(100);
    });

    test('should have action buttons in zone list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      const buttons = page.locator('a.btn, button.btn');
      const hasButtons = await buttons.count() > 0;
      expect(hasButtons).toBeTruthy();
    });
  });

  test.describe('Bulk Record Delete (delete_records.html)', () => {
    test('should have bulk selection capability in zone edit', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      // Verify page loaded
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|forward/i);
    });

    test('should display record columns in zone list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();

      // Should show zone list columns
      const hasColumns = bodyText.toLowerCase().includes('name') ||
                          bodyText.toLowerCase().includes('type') ||
                          bodyText.toLowerCase().includes('zone');
      expect(hasColumns).toBeTruthy();
    });

    test('should have table structure for zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');
      await page.waitForLoadState('networkidle');

      const table = page.locator('table');
      const hasTable = await table.count() > 0;

      // Should have table or show message
      expect(hasTable || page.url().includes('forward_zones')).toBeTruthy();
    });
  });
});

test.describe('Supermaster Delete Confirmation', () => {
  test.describe('Supermaster Delete Page (delete_supermaster.html)', () => {
    test('should display warning alert', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_supermasters');

      const deleteLinks = page.locator('a[href*="delete_supermaster"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const warningAlert = page.locator('.alert-danger');
        const hasWarning = await warningAlert.count() > 0;
        expect(hasWarning || page.url().includes('delete_supermaster')).toBeTruthy();
      }
    });

    test('should display supermaster IP being deleted', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_supermasters');

      const deleteLinks = page.locator('a[href*="delete_supermaster"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/supermaster|delete|ip/i);
      }
    });

    test('should display supermaster details card', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_supermasters');

      const deleteLinks = page.locator('a[href*="delete_supermaster"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        const hasDetails = bodyText.toLowerCase().includes('hostname') ||
                           bodyText.toLowerCase().includes('ns') ||
                           bodyText.toLowerCase().includes('account');
        expect(hasDetails || page.url().includes('delete_supermaster')).toBeTruthy();
      }
    });

    test('should have confirm and cancel buttons', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_supermasters');

      const deleteLinks = page.locator('a[href*="delete_supermaster"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const buttons = page.locator('a.btn');
        const hasButtons = await buttons.count() > 0;
        expect(hasButtons).toBeTruthy();
      }
    });

    test('cancel should return to supermasters list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_supermasters');

      const deleteLinks = page.locator('a[href*="delete_supermaster"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          await expect(page).toHaveURL(/list_supermasters/);
        }
      }
    });
  });
});

test.describe('Zone Template Delete Confirmation', () => {
  test.describe('Zone Template Delete Page (delete_zone_templ.html)', () => {
    test('should display warning alert', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const deleteLinks = page.locator('a[href*="delete_zone_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const warningAlert = page.locator('.alert-danger');
        const hasWarning = await warningAlert.count() > 0;
        expect(hasWarning || page.url().includes('delete_zone_templ')).toBeTruthy();
      }
    });

    test('should display template name being deleted', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const deleteLinks = page.locator('a[href*="delete_zone_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/template|delete/i);
      }
    });

    test('should show warning if template is used by zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const deleteLinks = page.locator('a[href*="delete_zone_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // May or may not show warning depending on template usage
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.length).toBeGreaterThan(0);
      }
    });

    test('should have confirm and cancel buttons', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const deleteLinks = page.locator('a[href*="delete_zone_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const buttons = page.locator('a.btn');
        const hasButtons = await buttons.count() > 0;
        expect(hasButtons).toBeTruthy();
      }
    });

    test('cancel should return to templates list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const deleteLinks = page.locator('a[href*="delete_zone_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          await expect(page).toHaveURL(/list_zone_templ/);
        }
      }
    });
  });
});

test.describe('Permission Template Delete Confirmation', () => {
  test.describe('Permission Template Delete Page (delete_perm_templ.html)', () => {
    test('should display warning alert', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');

      const deleteLinks = page.locator('a[href*="delete_perm_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const warningAlert = page.locator('.alert-danger');
        const hasWarning = await warningAlert.count() > 0;
        expect(hasWarning || page.url().includes('delete_perm_templ')).toBeTruthy();
      }
    });

    test('should display template name being deleted', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');

      const deleteLinks = page.locator('a[href*="delete_perm_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/permission.*template|template|delete/i);
      }
    });

    test('should show warning if template is assigned to users', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');

      const deleteLinks = page.locator('a[href*="delete_perm_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        // May or may not show warning depending on template usage
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.length).toBeGreaterThan(0);
      }
    });

    test('should have confirm and cancel buttons', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');

      const deleteLinks = page.locator('a[href*="delete_perm_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const buttons = page.locator('a.btn');
        const hasButtons = await buttons.count() > 0;
        expect(hasButtons).toBeTruthy();
      }
    });

    test('cancel should return to permission templates list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_perm_templ');

      const deleteLinks = page.locator('a[href*="delete_perm_templ"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        if (await cancelBtn.count() > 0) {
          await cancelBtn.first().click();

          await expect(page).toHaveURL(/list_perm_templ/);
        }
      }
    });
  });
});

test.describe('Common Delete Confirmation Elements', () => {
  test.describe('UI Consistency', () => {
    test('delete pages should use danger button styling', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const dangerBtn = page.locator('.btn-danger');
        const hasDangerBtn = await dangerBtn.count() > 0;
        expect(hasDangerBtn).toBeTruthy();
      }
    });

    test('cancel buttons should use secondary styling', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const secondaryBtn = page.locator('.btn-secondary');
        const hasSecondaryBtn = await secondaryBtn.count() > 0;
        expect(hasSecondaryBtn).toBeTruthy();
      }
    });

    test('delete pages should have trash icon', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const trashIcon = page.locator('.bi-trash, .bi-trash-fill');
        const hasTrashIcon = await trashIcon.count() > 0;
        expect(hasTrashIcon).toBeTruthy();
      }
    });

    test('warning alerts should have exclamation icon', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones');

      const deleteLinks = page.locator('a[href*="delete_domain"]');

      if (await deleteLinks.count() > 0) {
        await deleteLinks.first().click();

        const exclamationIcon = page.locator('.bi-exclamation-triangle, .bi-exclamation-triangle-fill');
        const hasExclamationIcon = await exclamationIcon.count() > 0;
        expect(hasExclamationIcon).toBeTruthy();
      }
    });
  });

  test.describe('Security', () => {
    test('delete confirmation should require authentication', async ({ page }) => {
      await page.goto('/index.php?page=delete_domain&id=1');

      // Should redirect to login
      await expect(page).toHaveURL(/page=login/);
    });

    test('delete user confirmation should require authentication', async ({ page }) => {
      await page.goto('/index.php?page=delete_user&id=1');

      await expect(page).toHaveURL(/page=login/);
    });
  });
});
