/**
 * Multi-Record Checkbox Handling Tests
 *
 * Tests for checkbox handling in multi-record add form.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

const ZONE_REASON = 'admin-zone.example.com must exist in the standard test data';

test.describe('Multi-Record Add Form Checkbox Handling', () => {
  test.describe('Add Record Form Structure', () => {
    test('should have add more records button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      // The multi-record form lives on /records/add, not on the zone edit page
      await page.goto(`/zones/${zoneId}/records/add`);
      await page.waitForLoadState('networkidle');

      await expect(page.locator('button:has-text("Add another record")')).toBeVisible();
    });

    test('should have checkbox for disabled records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');

      await expect(page.locator('input[type="checkbox"][name$="[disabled]"]').first()).toBeVisible();
    });
  });

  test.describe('Checkbox State Reset', () => {
    test('new record row should have unchecked disabled checkbox', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');

      const disabledCheckboxes = page.locator('input[type="checkbox"][name$="[disabled]"]');
      await expect(disabledCheckboxes.first()).toBeVisible();
      // The box mirrors the stored state, and no record in the test data is disabled
      await expect(page.locator('input[type="checkbox"][name$="[disabled]"]:checked')).toHaveCount(0);
    });

    test('should handle checkbox toggle correctly', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');

      const disabledCheckbox = page.locator('input[type="checkbox"][name$="[disabled]"]').first();
      const row = disabledCheckbox.locator('xpath=ancestor::tr[1]');
      await expect(disabledCheckbox).not.toBeChecked();

      // Ticking the box dims the row; nothing is saved until the form is submitted
      await disabledCheckbox.check();
      await expect(row).toHaveClass(/opacity-25/);

      await disabledCheckbox.uncheck();
      await expect(row).not.toHaveClass(/opacity-25/);
    });
  });

  test.describe('Form Input Types', () => {
    test('record form should have name input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');

      expect(await page.locator('input[name*="name"]').count()).toBeGreaterThan(0);
    });

    test('record form should have type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');

      expect(await page.locator('select[name*="type"]').count()).toBeGreaterThan(0);
    });

    test('record form should have content input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');

      const contentInput = page.locator('input[name*="content"], textarea[name*="content"]');
      expect(await contentInput.count()).toBeGreaterThan(0);
    });

    test('record form should have TTL input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');

      expect(await page.locator('input[name*="ttl"]').count()).toBeGreaterThan(0);
    });
  });

  test.describe('Multiple Record Rows', () => {
    test('should be able to add multiple record rows', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, ZONE_REASON).toBeTruthy();

      // templates/default/add_record.html: "Add another record" clones a tr.record-row
      await page.goto(`/zones/${zoneId}/records/add`);
      await page.waitForLoadState('networkidle');

      const rows = page.locator('#recordsTableBody tr.record-row');
      const initialRows = await rows.count();
      expect(initialRows).toBeGreaterThan(0);

      await page.locator('button:has-text("Add another record")').click();
      await expect(rows).toHaveCount(initialRows + 1);

      await page.locator('button:has-text("Add another record")').click();
      await expect(rows).toHaveCount(initialRows + 2);
    });
  });
});
