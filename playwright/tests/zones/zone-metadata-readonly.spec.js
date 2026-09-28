/**
 * Zone Metadata Read-Only View Tests
 *
 * Tests that users with view-only permissions can see metadata
 * in read-only mode with disabled controls and no edit buttons.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { expectAccessDenied } from '../../helpers/access.js';
import { findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

// The read-only controls only render for metadata the zone already carries, and
// the fixture data ships none, so admin seeds one row before the viewer looks.
const SEEDED_METADATA_VALUE = '192.0.2.77';

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage();
  await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

  const zoneId = await findZoneIdByName(page, 'viewer-zone.example.com');
  expect(zoneId, 'viewer-zone.example.com must exist').not.toBeNull();

  await page.goto(`/zones/${zoneId}/metadata`);
  if (await page.locator(`.metadata-content[value="${SEEDED_METADATA_VALUE}"]`).count() === 0) {
    await page.locator('#add-metadata-row').click();
    await page.locator('.metadata-kind-select').last().selectOption('ALLOW-AXFR-FROM');
    await page.locator('.metadata-content').last().fill(SEEDED_METADATA_VALUE);
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await expect(page.locator('[data-testid="system-message"]')).toContainText(/successfully/i);
  }

  await page.close();
});

test.afterAll(async ({ browser }) => {
  const page = await browser.newPage();
  await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

  const zoneId = await findZoneIdByName(page, 'viewer-zone.example.com');
  await page.goto(`/zones/${zoneId}/metadata`);
  const seeded = page.locator(`tr:has(.metadata-content[value="${SEEDED_METADATA_VALUE}"])`);
  if (await seeded.count() > 0) {
    await seeded.locator('.metadata-remove-row').click();
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await expect(page.locator('[data-testid="system-message"]')).toContainText(/successfully/i);
  }

  await page.close();
});

async function getTestZoneId(page) {
  await page.goto('/zones/forward?letter=all');

  // Viewer tests need a zone the viewer owns; resolve viewer-zone by name so we
  // never grab an arbitrary first-row zone the viewer cannot view metadata for.
  const namedRow = page.locator('tr', { hasText: 'viewer-zone.example.com' }).first();
  if (await namedRow.count() > 0) {
    const namedLink = namedRow.locator('a[href*="/edit"]').first();
    if (await namedLink.count() > 0) {
      const href = await namedLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)\/edit/);
      if (match) {
        return match[1];
      }
    }
  }

  // Fallback for non-fixture environments: first zone edit link on the page
  // Scoped to the table: an unscoped a[href*="/edit"] matches the nav dropdown first,
  // which carries no zone id, so this helper used to return null for every test.
  const editLink = page.locator('table a[href*="/zones/"][href*="/edit"]').first();
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
      expect(await metadataLink.count()).toBeGreaterThan(0);

      await expect(metadataLink.first()).toContainText('View Metadata');
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
      await expect(page.locator('.metadata-kind-select').first()).toBeDisabled();
    });

    test('should have readonly value inputs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/metadata`);
      const contentInput = page.locator(`.metadata-content[value="${SEEDED_METADATA_VALUE}"]`);
      await expect(contentInput).toHaveAttribute('readonly', '');
      await expect(contentInput).toHaveValue(SEEDED_METADATA_VALUE);
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
      expect(await metadataLink.count()).toBeGreaterThan(0);

      await expect(metadataLink.first()).toContainText('Metadata');
      const text = await metadataLink.first().textContent();
      expect(text.trim()).not.toMatch(/^View Metadata$/);
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
      await expectAccessDenied(page, 'table');
    });
  });
});
