/**
 * Batch PTR Records Tests
 *
 * Tests for batch PTR record creation functionality (GitHub issue #968)
 * - Batch PTR page access
 * - Form submission (regression test for #968 404 error)
 * - PTR record creation from forward zone
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Tests run serially to avoid database conflicts
test.describe.configure({ mode: 'serial' });

test.describe('Batch PTR Records (Issue #968)', () => {
  test.describe('Batch PTR Page Access', () => {
    test('should show error when accessing batch PTR with reverse zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'reverseIPv4');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toContain('Batch PTR record creation is not available for reverse zones');
    });

    test('should display batch PTR form elements', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);

      // Check for form elements
      const form = page.locator('form');
      expect(await form.count()).toBeGreaterThan(0);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|404/i);
    });

    test('should display network prefix selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);

      // Look for network prefix input or select
      const prefixInput = page.locator('input[name*="prefix"], select[name*="prefix"], input[name*="network"]');
      const bodyText = await page.locator('body').textContent();

      expect(await prefixInput.count()).toBeGreaterThan(0);

      // Page should load without errors
      expect(bodyText).not.toMatch(/fatal|exception|404/i);
    });

    test('should display PTR creation options', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      // Should have option for existing A records or all
      const hasOptions = bodyText.toLowerCase().includes('a record') ||
                        bodyText.toLowerCase().includes('forward') ||
                        bodyText.toLowerCase().includes('ptr');
      expect(bodyText).not.toMatch(/fatal|exception|404/i);
      expect(hasOptions).toBe(true);
    });
  });

  test.describe('Batch PTR Form Submission (Regression #968)', () => {
    test('should submit batch PTR form without 404 error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);

      // The bug was: action="/zones/batch-ptr&id=123" instead of "?id=123"
      const form = page.locator('form[action*="batch-ptr"]');
      await expect(form).toHaveAttribute('action', new RegExp(`/zones/batch-ptr\\?id=${zoneId}$`));

      // The required network prefix is empty on load, so fill it or the browser
      // blocks the submit and the route is never exercised. The range has no
      // seeded reverse zone, so the post exercises the route without writing PTRs.
      await page.locator('#network_prefix').fill('198.51.100.0/30');
      await page.locator('button[type="submit"]').first().click();

      await expect(page).toHaveURL(new RegExp(`/zones/batch-ptr\\?id=${zoneId}$`));
      await expect(page.locator('body')).not.toContainText(/404|not found/i);
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should handle empty batch PTR submission', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);

      await page.locator('button[type="submit"]').first().click();

      // The network prefix is required, so the empty form never leaves the page
      const form = page.locator('form[action*="batch-ptr"]');
      await expect(form).toHaveClass(/was-validated/);
      await expect(page.locator('#network_prefix')).toHaveValue('');
      await expect(page).toHaveURL(new RegExp(`/zones/batch-ptr\\?id=${zoneId}$`));
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Batch PTR Navigation', () => {
    test('should have link to batch PTR from zone edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);

      // Look for batch PTR link
      const batchPtrLink = page.locator('a[href*="batch-ptr"]');
      const bodyText = await page.locator('body').textContent();

      expect(await batchPtrLink.count()).toBeGreaterThan(0);

      // Page should load
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should return to zone list from batch PTR page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);

      // Cancel returns to the zone the batch PTR page was opened from
      const cancelLink = page.locator('form[action*="batch-ptr"] a:has-text("Cancel")');
      await expect(cancelLink).toHaveAttribute('href', new RegExp(`/zones/${zoneId}/edit$`));
      await cancelLink.click();

      await expect(page).toHaveURL(new RegExp(`/zones/${zoneId}/edit$`));
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Batch PTR Permissions', () => {
    test('admin should access batch PTR page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'standard test data zone must exist').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/denied|forbidden|unauthorized/i);
    });

    test('manager should access batch PTR page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);

      const zoneId = await getTestZoneId(page, 'manager');
      expect(zoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not have write access to batch PTR', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);

      const zoneId = await getTestZoneId(page, 'viewer');
      expect(zoneId, 'viewer-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/batch-ptr?id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();

      // Viewer should either see error or have read-only view
      const hasError = bodyText.toLowerCase().includes('denied') ||
                       bodyText.toLowerCase().includes('permission') ||
                       bodyText.toLowerCase().includes('not allowed');
      const hasForm = await page.locator('form button[type="submit"]').count() > 0;

      // Either denied or no submit button
      expect(hasError || !hasForm).toBeTruthy();
    });
  });
});

test.describe('Batch PTR IPv6 Support (Issue #1110)', () => {
  test('should show IPv6 option in IP version dropdown', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page, 'manager');
    expect(zoneId, 'standard test data zone must exist').toBeTruthy();

    await page.goto(`/zones/batch-ptr?id=${zoneId}`);

    const ipv6Option = page.locator('select#network_type option[value="ipv6"]');
    await expect(ipv6Option).toHaveCount(1);
    await expect(ipv6Option).toContainText('IPv6');
  });

  test('should keep matching-only checkbox available when IPv6 is selected', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page, 'manager');
    expect(zoneId, 'standard test data zone must exist').toBeTruthy();

    await page.goto(`/zones/batch-ptr?id=${zoneId}`);

    // Matching-only mode supports IPv6 since the nibble expansion fix
    await page.selectOption('#network_type', 'ipv6');

    const matchingCheckbox = page.locator('#only_matching_records');
    await expect(matchingCheckbox).toBeEnabled();
  });

  test('should keep matching-only checkbox available when switching back to IPv4', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page, 'manager');
    expect(zoneId, 'standard test data zone must exist').toBeTruthy();

    await page.goto(`/zones/batch-ptr?id=${zoneId}`);

    // Switch to IPv6 then back to IPv4
    await page.selectOption('#network_type', 'ipv6');
    await page.selectOption('#network_type', 'ipv4');

    const matchingCheckbox = page.locator('#only_matching_records');
    await expect(matchingCheckbox).toBeEnabled();
  });
});

test.describe('Batch PTR with Forward Zone', () => {
  test('should link PTR records to forward zone A records', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page, 'manager');
    expect(zoneId, 'standard test data zone must exist').toBeTruthy();

    await page.goto(`/zones/batch-ptr?id=${zoneId}`);

    // The form offers the forward-record option by name; the old sibling-label
    // filter matched nothing, so this test never checked anything
    const forwardOption = page.locator('input[name="create_forward_records"]');
    expect(await forwardOption.count()).toBeGreaterThan(0);

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should display forward zone selection', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page, 'manager');
    expect(zoneId, 'standard test data zone must exist').toBeTruthy();

    await page.goto(`/zones/batch-ptr?id=${zoneId}`);

    // Look for zone selection dropdown
    // The zone is carried by a text field, not a select; the select locator
    // matched nothing and the test passed regardless
    const zoneField = page.locator('input[name="domain"]');
    const bodyText = await page.locator('body').textContent();

    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(await zoneField.count()).toBeGreaterThan(0);
  });
});
