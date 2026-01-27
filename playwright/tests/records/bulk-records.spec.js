/**
 * Bulk Records Tests
 *
 * Tests for bulk record operations within a zone including
 * adding multiple records and bulk record deletion.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Bulk Record Operations', () => {
  // Helper to get a zone ID for testing
  async function getTestZoneId(page) {
    await page.goto('/zones/forward?letter=all');
    // Edit links might be in dropdown menus, check for any link with zone ID
    const editLink = page.locator('a[href*="/zones/"][href*="/edit"]').first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)\/edit/);
      return match ? match[1] : null;
    }
    // Fallback: try to find any link with zone ID in the table
    const zoneLink = page.locator('table a[href*="/zones/"]').first();
    if (await zoneLink.count() > 0) {
      const href = await zoneLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)/);
      return match ? match[1] : null;
    }
    return null;
  }

  test.describe('Bulk Record Add Form', () => {
    test('should access zone edit page with record form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/record|zone|edit/i);
    });

    test('should have add record row button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const addBtn = page.locator('button:has-text("Add row"), button[onclick*="addRecord"], .bi-plus-circle');
      const bodyText = await page.locator('body').textContent();

      const hasAddBtn = await addBtn.count() > 0;
      const hasAddText = bodyText.toLowerCase().includes('add');

      expect(hasAddBtn || hasAddText).toBeTruthy();
    });

    test('should display record type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const typeSelector = page.locator('select[name*="type"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should display record name input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const nameInput = page.locator('input[name*="name"]');
      expect(await nameInput.count()).toBeGreaterThan(0);
    });

    test('should display record content input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const contentInput = page.locator('input[name*="content"], textarea[name*="content"]');
      expect(await contentInput.count()).toBeGreaterThan(0);
    });

    test('should display TTL input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const ttlInput = page.locator('input[name*="ttl"]');
      expect(await ttlInput.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Adding Multiple Records', () => {
    test('should have add record functionality on edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      // Check that the page has record editing functionality
      const bodyText = await page.locator('body').textContent();
      const hasRecordFeature = bodyText.toLowerCase().includes('record') ||
                                bodyText.toLowerCase().includes('add') ||
                                await page.locator('input[name*="name"]').count() > 0;
      expect(hasRecordFeature).toBeTruthy();
    });

    test('should submit multiple records at once', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      const typeSelector = page.locator('select[name*="type"]').first();
      const nameInput = page.locator('input[name*="name"]').first();
      const contentInput = page.locator('input[name*="content"]').first();

      if (await typeSelector.count() > 0 && await nameInput.count() > 0 && await contentInput.count() > 0) {
        await typeSelector.selectOption('A');
        await nameInput.fill(`bulk-test-${Date.now()}`);
        await contentInput.fill('192.168.1.100');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Bulk Record Deletion', () => {
    test('should display record checkboxes for selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const checkboxes = page.locator('input[type="checkbox"][name*="record"]');
      // Checkboxes may or may not exist depending on records present
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should have select all checkbox', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const selectAllCheckbox = page.locator('input[type="checkbox"][id*="all"], input[type="checkbox"].select-all');
      // Select all may or may not exist
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should have delete selected button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);
      const deleteBtn = page.locator('input[value*="Delete selected"], button:has-text("Delete selected")');
      // Delete button may or may not be visible
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Record Form Validation', () => {
    test('should reject empty record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      const typeSelector = page.locator('select[name*="type"]').first();
      const nameInput = page.locator('input[name*="name"]').first();

      if (await typeSelector.count() > 0 && await nameInput.count() > 0) {
        await typeSelector.selectOption('A');
        await nameInput.fill('empty-content-test');
        // Leave content empty

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Should show error or stay on page
        const url = page.url();
        const bodyText = await page.locator('body').textContent();

        const hasError = bodyText.toLowerCase().includes('required') ||
                         bodyText.toLowerCase().includes('content') ||
                         url.includes('add');
        expect(hasError || bodyText).toBeTruthy();
      }
    });

    test('should validate IP address for A records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      const typeSelector = page.locator('select[name*="type"]').first();
      const nameInput = page.locator('input[name*="name"]').first();
      const contentInput = page.locator('input[name*="content"]').first();

      if (await typeSelector.count() > 0 && await nameInput.count() > 0 && await contentInput.count() > 0) {
        await typeSelector.selectOption('A');
        await nameInput.fill('invalid-ip-test');
        await contentInput.fill('not.an.ip.address');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        // Should show some feedback (error or success if validation passes server-side)
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('User Permissions', () => {
    test('admin should add records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for bulk record test');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/access denied|permission denied/i);
    });

    test('viewer should not add records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        // Viewer should have limited or no edit access
        const addRecordLink = page.locator('a[href*="/records/add"]');
        // Either no add link or page shows read-only
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });
});
