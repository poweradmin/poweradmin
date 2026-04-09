/**
 * Zone Metadata Editor Tests
 *
 * Tests for the zone metadata editor including navigation,
 * adding/editing/removing metadata, and permission checks.
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

test.describe('Zone Metadata Editor', () => {
  test('should show metadata button on zone edit page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/edit`);
    const metadataLink = page.locator('a[href*="/metadata"]');
    expect(await metadataLink.count()).toBeGreaterThan(0);
    await expect(metadataLink.first()).toContainText('Metadata');
  });

  test('should load metadata editor page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText).toContain('Edit Zone Metadata');
  });

  test('should display metadata kind dropdown', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);
    const kindSelect = page.locator('.metadata-kind-select').first();
    expect(await kindSelect.count()).toBeGreaterThan(0);

    const options = kindSelect.locator('option');
    const optionTexts = await options.allTextContents();
    expect(optionTexts).toContain('ALLOW-AXFR-FROM');
    expect(optionTexts).toContain('SOA-EDIT-API');
    expect(optionTexts).toContain('Custom');
  });

  test('should add metadata row and save', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);

    // Select ALLOW-AXFR-FROM kind
    const kindSelect = page.locator('.metadata-kind-select').first();
    await kindSelect.selectOption('ALLOW-AXFR-FROM');

    // Fill in value
    const contentInput = page.locator('.metadata-content').first();
    await contentInput.fill('192.0.2.10');

    // Save
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText).toContain('successfully');
  });

  test('should persist saved metadata on reload', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);
    await page.waitForLoadState('networkidle');

    const contentInput = page.locator('.metadata-content').first();
    const value = await contentInput.inputValue();
    expect(value).toBe('192.0.2.10');

    const kindSelect = page.locator('.metadata-kind-select').first();
    const selectedValue = await kindSelect.inputValue();
    expect(selectedValue).toBe('ALLOW-AXFR-FROM');
  });

  test('should add new row with add button', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);
    const initialRows = await page.locator('#metadata-rows tr').count();

    await page.locator('#add-metadata-row').click();
    const newRows = await page.locator('#metadata-rows tr').count();
    expect(newRows).toBe(initialRows + 1);
  });

  test('should remove metadata row', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);
    const initialRows = await page.locator('#metadata-rows tr').count();

    if (initialRows > 1) {
      await page.locator('.metadata-remove-row').last().click();
      const newRows = await page.locator('#metadata-rows tr').count();
      expect(newRows).toBe(initialRows - 1);
    }
  });

  test('should show custom kind input when Custom is selected', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);

    const kindSelect = page.locator('.metadata-kind-select').first();
    await kindSelect.selectOption('__CUSTOM__');

    const customInput = page.locator('.metadata-custom-kind-wrapper').first();
    await expect(customInput).not.toHaveClass(/d-none/);
  });

  test('should save and load SOA-EDIT-API metadata', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);

    // Add new row
    await page.locator('#add-metadata-row').click();

    // Select SOA-EDIT-API on the last row
    const lastKindSelect = page.locator('.metadata-kind-select').last();
    await lastKindSelect.selectOption('SOA-EDIT-API');

    const lastContentInput = page.locator('.metadata-content').last();
    await lastContentInput.fill('DEFAULT');

    // Save
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText).toContain('successfully');
  });

  test('should clean up test metadata', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);

    // Remove all rows
    const removeButtons = page.locator('.metadata-remove-row');
    const count = await removeButtons.count();
    for (let i = count - 1; i >= 0; i--) {
      await removeButtons.nth(i).click();
    }

    // Save empty metadata
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should have CSRF token in form', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);
    const csrfToken = page.locator('input[name="_token"]');
    expect(await csrfToken.count()).toBe(1);
  });

  test('should have breadcrumb navigation', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/metadata`);
    const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
    expect(await breadcrumb.count()).toBe(1);

    const backLink = page.locator(`a[href*="/zones/${zoneId}/edit"]`);
    expect(await backLink.count()).toBeGreaterThan(0);
  });
});
