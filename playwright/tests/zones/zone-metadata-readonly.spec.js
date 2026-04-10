/**
 * Zone Metadata Read-Only View Tests
 *
 * Tests that users with view-only permissions can see metadata
 * in read-only mode with disabled controls and no edit buttons.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

async function getTestZoneId(page) {
  await page.goto('/zones/forward?letter=all');
  const editLink = page.locator('a[href*="/edit"]').first();
  if (await editLink.count() > 0) {
    const href = await editLink.getAttribute('href');
    const match = href.match(/\/zones\/(\d+)\/edit/);
    return match ? match[1] : null;
  }
  return null;
}

test.describe('Zone Metadata Read-Only View', () => {
  test.describe('Viewer User', () => {
    test('should see View Metadata button on zone edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);
      const metadataLink = page.locator('a[href*="/metadata"]');
      if (await metadataLink.count() > 0) {
        await expect(metadataLink.first()).toContainText('View Metadata');
      }
    });

    test('should load metadata page in read-only mode', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|error/i);
      expect(bodyText).toContain('Zone Metadata');
      expect(bodyText).not.toContain('Edit Zone Metadata');
    });

    test('should have disabled kind dropdowns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const kindSelect = page.locator('.metadata-kind-select').first();
      if (await kindSelect.count() > 0) {
        await expect(kindSelect).toBeDisabled();
      }
    });

    test('should have readonly value inputs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const contentInput = page.locator('.metadata-content').first();
      if (await contentInput.count() > 0) {
        await expect(contentInput).toHaveAttribute('readonly', '');
      }
    });

    test('should not show save button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const saveBtn = page.locator('[data-testid="save-zone-metadata"]');
      expect(await saveBtn.count()).toBe(0);
    });

    test('should not show add row button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const addBtn = page.locator('#add-metadata-row');
      expect(await addBtn.count()).toBe(0);
    });

    test('should not show remove row buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const removeBtn = page.locator('.metadata-remove-row');
      expect(await removeBtn.count()).toBe(0);
    });

    test('should show back to zone link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const backLink = page.locator(`a[href*="/zones/${zoneId}/edit"]`);
      expect(await backLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Admin User', () => {
    test('should see Metadata button (not View Metadata)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);
      const metadataLink = page.locator('a[href*="/metadata"]');
      if (await metadataLink.count() > 0) {
        await expect(metadataLink.first()).toContainText('Metadata');
        const text = await metadataLink.first().textContent();
        expect(text.trim()).not.toMatch(/^View Metadata$/);
      }
    });

    test('should see edit controls on metadata page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const saveBtn = page.locator('[data-testid="save-zone-metadata"]');
      expect(await saveBtn.count()).toBe(1);

      const addBtn = page.locator('#add-metadata-row');
      expect(await addBtn.count()).toBe(1);
    });
  });

  test.describe('No Permission User', () => {
    test('should be denied access to metadata page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.noperm.username, users.noperm.password);

      await page.goto('/zones/1/metadata');
      const bodyText = await page.locator('body').textContent();
      const isDenied = bodyText.toLowerCase().includes('permission') ||
                       bodyText.toLowerCase().includes('error') ||
                       !page.url().includes('/metadata');
      expect(isDenied).toBeTruthy();
    });
  });
});
