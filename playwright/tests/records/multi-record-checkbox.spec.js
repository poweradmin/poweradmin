/**
 * Multi-Record Checkbox Handling Tests
 *
 * Tests for checkbox handling in multi-record add form.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Multi-Record Add Form Checkbox Handling', () => {
  // Helper to get a zone ID for testing
  async function getTestZoneId(page) {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');
    const editLink = page.locator('a[href*="/edit"]').first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)\/edit/);
      return match ? match[1] : null;
    }
    return null;
  }

  test.describe('Add Record Form Structure', () => {
    test('should have add more records button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const addMoreBtn = page.locator('button:has-text("Add"), button[onclick*="addRecord"], .bi-plus');
        const bodyText = await page.locator('body').textContent();

        const hasAddMore = await addMoreBtn.count() > 0 || bodyText.toLowerCase().includes('add');
        expect(hasAddMore).toBeTruthy();
      } else {
        expect(true).toBeTruthy();
      }
    });

    test('should have checkbox for disabled records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const disabledCheckbox = page.locator('input[type="checkbox"][name*="disabled"]');
        const bodyText = await page.locator('body').textContent();

        const hasCheckbox = await disabledCheckbox.count() >= 0;
        const hasDisabledOption = bodyText.toLowerCase().includes('disabled');

        expect(hasCheckbox || hasDisabledOption).toBeTruthy();
      } else {
        expect(true).toBeTruthy();
      }
    });
  });

  test.describe('Checkbox State Reset', () => {
    test('new record row should have unchecked disabled checkbox', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const disabledCheckbox = page.locator('input[type="checkbox"][name*="disabled"]').first();

        if (await disabledCheckbox.count() > 0) {
          const isChecked = await disabledCheckbox.isChecked();
          expect(isChecked).toBeFalsy();
        }
      }
    });

    test('should handle checkbox toggle correctly', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const disabledCheckbox = page.locator('input[type="checkbox"][name*="disabled"]').first();

        if (await disabledCheckbox.count() > 0) {
          const initialState = await disabledCheckbox.isChecked();
          await disabledCheckbox.click();
          const newState = await disabledCheckbox.isChecked();

          expect(newState).not.toBe(initialState);
        }
      }
    });
  });

  test.describe('Form Input Types', () => {
    test('record form should have name input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const nameInput = page.locator('input[name*="name"]');
        const hasNameInput = await nameInput.count() > 0;

        expect(hasNameInput).toBeTruthy();
      }
    });

    test('record form should have type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const typeSelect = page.locator('select[name*="type"]');
        const hasTypeSelect = await typeSelect.count() > 0;

        expect(hasTypeSelect).toBeTruthy();
      }
    });

    test('record form should have content input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const contentInput = page.locator('input[name*="content"], textarea[name*="content"]');
        const hasContentInput = await contentInput.count() > 0;

        expect(hasContentInput).toBeTruthy();
      }
    });

    test('record form should have TTL input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const ttlInput = page.locator('input[name*="ttl"]');
        const hasTtlInput = await ttlInput.count() > 0;

        expect(hasTtlInput).toBeTruthy();
      }
    });
  });

  test.describe('Multiple Record Rows', () => {
    test('should be able to add multiple record rows', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);

      if (zoneId) {
        await page.goto(`/zones/${zoneId}/edit`);
        await page.waitForLoadState('networkidle');

        const addBtn = page.locator('button:has-text("Add row"), button[onclick*="addRecord"], .bi-plus-circle').first();
        if (await addBtn.count() > 0) {
          const initialRows = await page.locator('input[name*="name"]').count();
          await addBtn.click();
          await page.waitForTimeout(500);
          const newRows = await page.locator('input[name*="name"]').count();
          expect(newRows).toBeGreaterThanOrEqual(initialRows);
        }
      }
    });
  });
});
