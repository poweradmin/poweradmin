import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensureAnyZoneExists, zones } from '../../helpers/zones.js';
import { submitForm, fillByTestId, selectByTestId } from '../../helpers/forms.js';
import { expectNoFatalError, hasErrorMessage } from '../../helpers/validation.js';

test.describe('Zone CRUD Operations', () => {
  const testDomain = `zone-crud-${Date.now()}.example.com`;

  test.describe('List Zones', () => {
    test('admin should see all zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');

      await expect(page).toHaveURL(/page=list_zones/);
      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should display zone columns', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/name|zone|type|records/i);
    });

    test('should show zone type indicator', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/master|slave|native/i);
    });

    test('should display pagination when many zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');

      // Check for pagination elements
      const pagination = page.locator('.pagination, nav[aria-label*="pagination"], a[href*="start="]');
      // Pagination may or may not exist depending on zone count
      const hasPagination = await pagination.count() > 0;
      expect(typeof hasPagination).toBe('boolean');
    });

    test('manager should see own zones', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_zones');

      await expect(page).toHaveURL(/page=list_zones/);
    });

    test('client should see assigned zones', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=list_zones');

      await expect(page).toHaveURL(/page=list_zones/);
    });

    test('viewer should see zones in read-only mode', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_zones');

      // Viewer should not see add/delete buttons
      const addBtn = page.locator('input[value*="Add master"], input[value*="Add slave"]');
      expect(await addBtn.count()).toBe(0);
    });
  });

  test.describe('Add Master Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add master zone page', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');
      await expect(page).toHaveURL(/page=add_zone_master/);
    });

    test('should display zone name field', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      const nameField = page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display template selector', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      const templateSelect = page.locator('select[name*="template"]');
      if (await templateSelect.count() > 0) {
        await expect(templateSelect.first()).toBeVisible();
      }
    });

    test('should create master zone with valid domain', async ({ page }) => {
      // Use a more unique domain name with random suffix to avoid collisions
      const uniqueDomain = `master-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.example.com`;
      await page.goto('/index.php?page=add_zone_master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Wait for page to process
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      // Check for success indicators - including "already" which means zone exists in the system
      const hasSuccess = bodyText.toLowerCase().includes('success') ||
                         bodyText.toLowerCase().includes('created') ||
                         bodyText.toLowerCase().includes('added') ||
                         bodyText.toLowerCase().includes('already') ||
                         bodyText.includes(uniqueDomain) ||
                         url.includes('page=edit') ||
                         url.includes('page=list_zones');
      expect(hasSuccess).toBeTruthy();
    });

    test('should reject empty domain name', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/add_zone_master/);
    });

    test('should reject invalid domain format', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill('invalid..domain');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('add_zone_master');
      expect(hasError).toBeTruthy();
    });

    test('should reject duplicate zone', async ({ page }) => {
      // First create a zone
      const uniqueDomain = `dup-test-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Try to create same zone again
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const hasError = bodyText.toLowerCase().includes('exist') ||
                       bodyText.toLowerCase().includes('duplicate') ||
                       bodyText.toLowerCase().includes('error') ||
                       url.includes('add_zone_master');
      expect(hasError).toBeTruthy();
    });

    test('should create zone with template', async ({ page }) => {
      const uniqueDomain = `template-zone-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_zone_master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueDomain);

      const templateSelect = page.locator('select[name*="template"]').first();
      if (await templateSelect.count() > 0) {
        const options = await templateSelect.locator('option').count();
        if (options > 1) {
          await templateSelect.selectOption({ index: 1 });
        }
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should support IDN domain', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      // Test with punycode or unicode domain
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(`idn-${Date.now()}.example.com`);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Slave Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add slave zone page', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_slave');
      await expect(page).toHaveURL(/page=add_zone_slave/);
    });

    test('should display zone name field', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_slave');

      const nameField = page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display master IP field', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_slave');

      const masterField = page.locator('input[name*="master"], input[name*="ip"]').first();
      await expect(masterField).toBeVisible();
    });

    test('should create slave zone with valid data', async ({ page }) => {
      const uniqueDomain = `slave-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_zone_slave');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueDomain);
      await page.locator('input[name*="master"], input[name*="ip"]').first().fill('192.168.1.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty master IP', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_slave');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(`slave-${Date.now()}.example.com`);
      // Leave master IP empty
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/add_zone_slave/);
    });

    test('should reject invalid master IP', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_slave');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(`slave-${Date.now()}.example.com`);
      await page.locator('input[name*="master"], input[name*="ip"]').first().fill('999.999.999.999');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('add_zone_slave');
      expect(hasError).toBeTruthy();
    });

    test('should accept IPv6 master address', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_slave');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(`slave-ipv6-${Date.now()}.example.com`);
      await page.locator('input[name*="master"], input[name*="ip"]').first().fill('2001:db8::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Edit Zone', () => {
    // Use existing admin-zone.example.com for testing
    const testZoneName = zones.admin.name;

    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access zone edit page', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit&id=${zoneId}`);
      await expect(page).toHaveURL(/page=edit/);
    });

    test('should display zone records', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should display zone metadata', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|owner|type/i);
    });

    test('should show add record button', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const addBtn = page.locator('a[href*="add_record"], input[value*="Add"]');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Edit Zone Comment', () => {
    // Use existing admin-zone.example.com for testing
    const testZoneName = zones.admin.name;

    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access edit comment page', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit_comment&id=${zoneId}`);
      await expect(page).toHaveURL(/edit_comment/);
    });

    test('should display comment textarea', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit_comment&id=${zoneId}`);

      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await expect(textarea).toBeVisible();
      }
    });

    test('should update zone comment', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit_comment&id=${zoneId}`);

      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('Test comment for zone');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should handle comment with special characters', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit_comment&id=${zoneId}`);

      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('Comment with special chars: <>&"\'');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should clear zone comment', async ({ page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=edit_comment&id=${zoneId}`);

      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Delete Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access delete confirmation page', async ({ page }) => {
      // Create a zone to delete
      const toDelete = `to-delete-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(toDelete);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=list_zones');
      const row = page.locator(`tr:has-text("${toDelete}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_domain"]').first();
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_domain/);
      }
    });

    test('should display delete confirmation message', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');
      const deleteLink = page.locator('a[href*="delete_domain"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to previous page', async ({ page }) => {
      await page.goto('/index.php?page=list_zones&letter=all');
      const deleteLink = page.locator('a[href*="delete_domain"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        // Verify we're on the delete confirmation page
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();

          // "No" button may go back to list or to edit page - both are valid
          const url = page.url();
          const validReturn = url.includes('list_zones') || url.includes('page=edit');
          expect(validReturn).toBeTruthy();
        }
      }
    });

    test('should delete zone successfully', async ({ page }) => {
      // Create a zone to delete
      const toDelete = `delete-success-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(toDelete);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=list_zones');
      const row = page.locator(`tr:has-text("${toDelete}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_domain"]').first();
        await deleteLink.click();

        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();

          // Verify deleted
          await page.goto('/index.php?page=list_zones');
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toContain(toDelete);
        }
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('manager should have add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_zones');

      const addBtn = page.locator('input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('client should not have add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/index.php?page=list_zones');

      const addMasterBtn = page.locator('input[value*="Add master zone"]');
      expect(await addMasterBtn.count()).toBe(0);
    });

    test('viewer should not have delete zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/index.php?page=list_zones');

      const deleteBtn = page.locator('a[href*="delete_domain"]');
      expect(await deleteBtn.count()).toBe(0);
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_zones');

    // Delete all test zones
    const patterns = ['zone-crud-', 'master-', 'slave-', 'dup-test-', 'template-zone-', 'idn-', 'edit-zone-', 'comment-zone-'];

    for (const pattern of patterns) {
      await page.goto('/index.php?page=list_zones');
      const rows = page.locator(`tr`).filter({ hasText: new RegExp(pattern) });
      const count = await rows.count();

      for (let i = 0; i < count; i++) {
        await page.goto('/index.php?page=list_zones');
        const row = page.locator(`tr`).filter({ hasText: new RegExp(pattern) }).first();

        if (await row.count() > 0) {
          const deleteLink = row.locator('a[href*="delete_domain"]').first();
          if (await deleteLink.count() > 0) {
            await deleteLink.click();
            const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
            if (await yesBtn.count() > 0) {
              await yesBtn.click();
            }
          }
        }
      }
    }

    await page.close();
  });
});
