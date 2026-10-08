/**
 * Zone Metadata Editor Tests
 *
 * Tests for the zone metadata editor including navigation,
 * adding/editing/removing metadata, and permission checks.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';

test.describe.configure({ mode: 'serial' });

test.describe('Zone Metadata Editor', () => {
  test('should show metadata button on zone edit page', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/edit`);
    // Auto-retrying: a one-shot count() here races the edit page render
    const metadataLink = page.locator('a[href*="/metadata"]');
    await expect(metadataLink.first()).toBeVisible();
    await expect(metadataLink.first()).toContainText('Metadata');
  });

  test('should load metadata editor page', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText).toContain('Edit Zone Metadata');
  });

  test('should display metadata kind dropdown', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);
    const kindSelect = page.locator('.metadata-kind-select').first();
    expect(await kindSelect.count()).toBeGreaterThan(0);

    const options = kindSelect.locator('option');
    const optionTexts = await options.allTextContents();
    expect(optionTexts).toContain('ALLOW-AXFR-FROM');
    expect(optionTexts).toContain('SOA-EDIT-API');
    expect(optionTexts).toContain('Custom');
  });

  test('should add metadata row and save', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);

    // Select ALLOW-AXFR-FROM kind
    const kindSelect = page.locator('.metadata-kind-select').first();
    await kindSelect.selectOption('ALLOW-AXFR-FROM');

    // Fill in value
    const contentInput = page.locator('.metadata-content').first();
    await contentInput.fill('192.0.2.10');

    // Save
    await page.locator('[data-testid="save-zone-metadata"]').click();

    // The controller redirects back to the metadata page with a flash message, so
    // wait for the alert rather than reading the body once and racing the redirect.
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await expect(page.locator('[data-testid="system-message"]')).toContainText(/successfully/i);
  });

  test('should persist saved metadata on reload', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);
    await page.locator('.metadata-kind-select').first().selectOption('ALLOW-AXFR-FROM');
    await page.locator('.metadata-content').first().fill('192.0.2.10');
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await expect(page.locator('[data-testid="system-message"]')).toContainText(/successfully/i);

    await page.goto(`/zones/${zoneId}/metadata`);
    await expect(page.locator('.metadata-content').first()).toHaveValue('192.0.2.10');
    await expect(page.locator('.metadata-kind-select').first()).toHaveValue('ALLOW-AXFR-FROM');
  });

  test('should add new row with add button', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);
    const initialRows = await page.locator('#metadata-rows tr').count();

    await page.locator('#add-metadata-row').click();
    const newRows = await page.locator('#metadata-rows tr').count();
    expect(newRows).toBe(initialRows + 1);
  });

  test('should remove metadata row', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);

    // Add a row first so there is always one to remove
    await page.locator('#add-metadata-row').click();
    const initialRows = await page.locator('#metadata-rows tr').count();
    expect(initialRows).toBeGreaterThan(0);

    await page.locator('.metadata-remove-row').last().click();
    await expect(page.locator('#metadata-rows tr')).toHaveCount(initialRows - 1);
  });

  test('should show custom kind input when Custom is selected', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);

    const kindSelect = page.locator('.metadata-kind-select').first();
    await kindSelect.selectOption('__CUSTOM__');

    const customInput = page.locator('.metadata-custom-kind-wrapper').first();
    await expect(customInput).not.toHaveClass(/d-none/);
  });

  test('should save and load SOA-EDIT-API metadata', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);

    // Add new row
    await page.locator('#add-metadata-row').click();

    // Select SOA-EDIT-API on the last row
    const lastKindSelect = page.locator('.metadata-kind-select').last();
    await lastKindSelect.selectOption('SOA-EDIT-API');

    // Kinds with a fixed vocabulary swap the free-text input for a select and
    // disable the input, so SOA-EDIT-API takes its value from the dropdown.
    const lastContentSelect = page.locator('.metadata-content-select').last();
    await lastContentSelect.selectOption('DEFAULT');

    // Save
    await page.locator('[data-testid="save-zone-metadata"]').click();

    // The controller redirects back to the metadata page with a flash message, so
    // wait for the alert rather than reading the body once and racing the redirect.
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await expect(page.locator('[data-testid="system-message"]')).toContainText(/successfully/i);
  });

  test('should accept two values for a multi-value kind', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);

    // PowerDNS reads every TSIG-ALLOW-DNSUPDATE row, so a zone may carry more
    // than one update key.
    for (const key of ['update-key-one', 'update-key-two']) {
      await page.locator('#add-metadata-row').click();
      await page.locator('.metadata-kind-select').last().selectOption('TSIG-ALLOW-DNSUPDATE');
      await page.locator('.metadata-content').last().fill(key);
    }

    await page.locator('[data-testid="save-zone-metadata"]').click();

    // The badge legend always says "Single value", so assert on the alert only.
    await expect(page.locator('[data-testid="system-message"]')).toContainText(/successfully/i);
    await expect(page.locator('[data-testid="system-message"]')).not.toContainText(/single value/i);

    await page.goto(`/zones/${zoneId}/metadata`);
    const values = await page.locator('.metadata-content').evaluateAll(
      (inputs) => inputs.map((input) => input.value)
    );
    expect(values).toContain('update-key-one');
    expect(values).toContain('update-key-two');
  });

  test('should save an empty metadata set', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);

    // Fill one row, then remove every row so the save carries no metadata
    await page.locator('#add-metadata-row').click();
    const removeButtons = page.locator('.metadata-remove-row');
    const count = await removeButtons.count();
    for (let i = count - 1; i >= 0; i--) {
      await removeButtons.nth(i).click();
    }

    // Save empty metadata
    await page.locator('[data-testid="save-zone-metadata"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should have CSRF token in form', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);
    await expect(page.locator('input[name="_token"]')).toHaveCount(1);
  });

  test('should have breadcrumb navigation', async ({ page, tempZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

    await page.goto(`/zones/${zoneId}/metadata`);
    await expect(page.locator('nav[aria-label="breadcrumb"]')).toHaveCount(1);
    await expect(page.locator(`a[href*="/zones/${zoneId}/edit"]`).first()).toBeVisible();
  });
});
