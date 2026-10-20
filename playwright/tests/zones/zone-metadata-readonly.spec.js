/**
 * Zone Metadata Read-Only View Tests
 *
 * Tests that users with view-only permissions can see metadata
 * in read-only mode with disabled controls and no edit buttons.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { expectAccessDenied } from '../../helpers/access.js';
import { createTempZone, deleteZoneById, uniqueZoneName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

// The read-only controls only render for metadata the zone already carries, so
// admin builds a throwaway zone owned by the viewer user and gives it one row.
const SEEDED_METADATA_VALUE = '192.0.2.77';
let viewerZoneId = null;

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage();
  try {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    const zone = await createTempZone(page, { name: uniqueZoneName('metaro') });
    viewerZoneId = zone.id;

    await page.goto(`/zones/${viewerZoneId}/ownership`);
    await page.locator('.user-item', { has: page.locator('small', { hasText: '(viewer)' }) }).locator('input[name="newowner"]').check();
    await page.locator('#add-owner-btn').click();
    await page.waitForLoadState('networkidle');

    await page.goto(`/zones/${viewerZoneId}/metadata`);
    await page.locator('#add-metadata-row').click();
    await page.locator('.metadata-kind-select').last().selectOption('ALLOW-AXFR-FROM');
    await page.locator('.metadata-content').last().fill(SEEDED_METADATA_VALUE);
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await expect(page.locator('[data-testid="system-message"]')).toContainText(/successfully/i);
  } finally {
    await page.close();
  }
});

test.afterAll(async ({ browser }) => {
  if (!viewerZoneId) return;
  const page = await browser.newPage();
  try {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await deleteZoneById(page, viewerZoneId);
  } finally {
    await page.close();
  }
});

test.describe('Zone Metadata Read-Only View', () => {
  test.describe('Viewer User', () => {
    test('should see View Metadata button on zone edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/edit`);
      const metadataLink = page.locator('a[href*="/metadata"]');
      expect(await metadataLink.count()).toBeGreaterThan(0);

      await expect(metadataLink.first()).toContainText('View Metadata');
    });

    test('should load metadata page in read-only mode', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/metadata`);
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|error/i);
      expect(bodyText).toContain('Zone Metadata');
      expect(bodyText).not.toContain('Edit Zone Metadata');
    });

    test('should have disabled kind dropdowns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/metadata`);
      await expect(page.locator('.metadata-kind-select').first()).toBeDisabled();
    });

    test('should have readonly value inputs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/metadata`);
      const contentInput = page.locator(`.metadata-content[value="${SEEDED_METADATA_VALUE}"]`);
      await expect(contentInput).toHaveAttribute('readonly', '');
      await expect(contentInput).toHaveValue(SEEDED_METADATA_VALUE);
    });

    test('should not show save button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/metadata`);
      const saveBtn = page.locator('[data-testid="save-zone-metadata"]');
      expect(await saveBtn.count()).toBe(0);
    });

    test('should not show add row button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/metadata`);
      const addBtn = page.locator('#add-metadata-row');
      expect(await addBtn.count()).toBe(0);
    });

    test('should not show remove row buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/metadata`);
      const removeBtn = page.locator('.metadata-remove-row');
      expect(await removeBtn.count()).toBe(0);
    });

    test('should show back to zone link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/metadata`);
      const backLink = page.locator(`a[href*="/zones/${zoneId}/edit"]`);
      expect(await backLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Admin User', () => {
    test('should see Metadata button (not View Metadata)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = viewerZoneId;

      await page.goto(`/zones/${zoneId}/edit`);
      const metadataLink = page.locator('a[href*="/metadata"]');
      expect(await metadataLink.count()).toBeGreaterThan(0);

      await expect(metadataLink.first()).toContainText('Metadata');
      const text = await metadataLink.first().textContent();
      expect(text.trim()).not.toMatch(/^View Metadata$/);
    });

    test('should see edit controls on metadata page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = viewerZoneId;

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
      await expectAccessDenied(page, 'table');
    });
  });
});
