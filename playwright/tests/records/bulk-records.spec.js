/**
 * Bulk Records Tests
 *
 * Tests for bulk record operations within a zone including
 * adding multiple records and bulk record deletion.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Bulk Record Operations', () => {
  test.describe('Bulk Record Add Form', () => {
    test('should access zone edit page with record form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/record|zone|edit/i);
    });

    test('should have add record row button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      // edit.html offers either "Multi-record mode" or "Add record", both linking to /records/add
      await expect(page.locator('a[href$="/records/add"]:not(.dropdown-item)').first()).toBeVisible();
    });

    test('should display record type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      const typeSelector = page.locator('select[name*="type"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should display record name input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      const nameInput = page.locator('input[name*="name"]');
      expect(await nameInput.count()).toBeGreaterThan(0);
    });

    test('should display record content input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      const contentInput = page.locator('input[name*="content"], textarea[name*="content"]');
      expect(await contentInput.count()).toBeGreaterThan(0);
    });

    test('should display TTL input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      const ttlInput = page.locator('input[name*="ttl"]');
      expect(await ttlInput.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Adding Multiple Records', () => {
    test('should have add record functionality on edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      // Check that the page has record editing functionality
      const bodyText = await page.locator('body').textContent();
      const hasRecordFeature = bodyText.toLowerCase().includes('record') ||
                                bodyText.toLowerCase().includes('add') ||
                                await page.locator('input[name*="name"]').count() > 0;
      expect(hasRecordFeature).toBeTruthy();
    });

    test('should submit multiple records at once', async ({ page, tempZone }) => {
      test.slow(); // creates throwaway zones on top of the test itself
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = tempZone.id;

      await page.goto(`/zones/${zoneId}/records/add`);

      const stamp = Date.now();
      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await page.locator('input[name="records[0][name]"]').fill(`bulk-a-${stamp}`);
      await page.locator('input[name="records[0][content]"]').fill('192.0.2.60');

      await page.locator('button', { hasText: 'Add another record' }).click();
      await page.locator('select[name="records[1][type]"]').selectOption('A');
      await page.locator('input[name="records[1][name]"]').fill(`bulk-b-${stamp}`);
      await page.locator('input[name="records[1][content]"]').fill('192.0.2.61');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Both rows must be written, not just the first
      await expect(page.locator('body')).toContainText('2 record(s) have been added successfully.');
    });
  });

  test.describe('Bulk Record Deletion', () => {
    test('should display record checkboxes for selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      const checkboxes = page.locator('input[type="checkbox"][name*="record"]');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(await checkboxes.count()).toBeGreaterThan(0);
    });

    test('should have select all checkbox', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      // The control is #select_edit_records; the old id*="all" selector matched nothing
      const selectAllCheckbox = page.locator('#select_edit_records');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(await selectAllCheckbox.count()).toBeGreaterThan(0);
    });

    test('should have delete selected button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      // The control is #delete-selected-records, and it is disabled until a row
      // is ticked, so the old text-matching selector never found it
      const deleteBtn = page.locator('#delete-selected-records');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(await deleteBtn.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Record Form Validation', () => {
    test('should reject empty record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await page.locator('input[name="records[0][name]"]').fill('empty-content-test');
      // Leave content empty

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Content is required, so the browser blocks the submit and nothing is written
      const form = page.locator('form:has(input[name="records[0][content]"])');
      await expect(form).toHaveClass(/was-validated/);
      await expect(page.locator('input[name="records[0][content]"]')).toHaveValue('');
      await expect(page).toHaveURL(/\/records\/add/);
    });

    test('should validate IP address for A records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await page.locator('input[name="records[0][name]"]').fill('invalid-ip-test');
      await page.locator('input[name="records[0][content]"]').fill('not.an.ip.address');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page).toHaveURL(/\/records\/add/);
      await expect(page.locator('.alert-danger')).toContainText('Invalid IPv4 address format.');
    });
  });

  test.describe('User Permissions', () => {
    test('admin should add records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${zoneId}/records/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/access denied|permission denied/i);
    });

    test('viewer should not add records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      // Scoped to the table: an unscoped a[href*="/edit"] matches the nav dropdown first
      const editLink = page.locator('table a[href*="/zones/"][href*="/edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      // The point of the test: a viewer is offered no way to add a record
      await expect(page.locator('a[href*="/records/add"]')).toHaveCount(0);
    });
  });
});
