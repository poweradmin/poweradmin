/**
 * Matching Record Creation Tests (Issue #1104)
 *
 * Tests for the "Add PTR" and "Add A/AAAA" checkbox functionality
 * that creates matching records in corresponding zones.
 *
 * - A record -> matching PTR record in reverse zone
 * - PTR record -> matching A record in forward zone
 *
 * Requires test data loaded via import-test-data.sh:
 * - Forward zone: manager-zone.example.com
 * - Reverse zone: 2.0.192.in-addr.arpa
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Tests run serially to avoid database conflicts
test.describe.configure({ mode: 'serial' });

// Check if a record with the given content exists in a zone.
// Uses the search filter to find records across all pages.
async function recordExistsInZone(page, zoneId, recordName, recordType, recordContent) {
  await page.goto(`/zones/${zoneId}/edit`);

  // Use the content filter to narrow results and avoid pagination issues
  const contentFilter = page.locator('input[placeholder="Filter by content"]');
  if (await contentFilter.count() > 0) {
    await contentFilter.fill(recordContent);
    await page.locator('button:has-text("Apply")').click();
    await page.waitForLoadState('domcontentloaded');
  }

  const contentInput = page.locator(`input[value="${recordContent}"]`);
  return await contentInput.count() > 0;
}

test.describe('Matching Record Creation (Issue #1104)', () => {
  const timestamp = Date.now();

  test.describe('A record with Add PTR checkbox', () => {
    const testHostname = `match-ptr-${timestamp}`;
    const testIP = '192.0.2.99';

    test('should create matching PTR record when adding A record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Find forward zone
      const forwardZoneId = await getTestZoneId(page, 'manager');
      expect(forwardZoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      // Find reverse zone (to verify later)
      const reverseZoneId = await getTestZoneId(page, 'reverseIPv4');
      expect(reverseZoneId, '2.0.192.in-addr.arpa must exist in the standard test data').toBeTruthy();

      // Add A record with PTR checkbox
      await page.goto(`/zones/${forwardZoneId}/records/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Fill in the A record
      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await page.locator('input[name="records[0][name]"]').fill(testHostname);
      await page.locator('input[name="records[0][content]"]').fill(testIP);

      // Check the PTR checkbox (make visible first since JS hides it for non-A types)
      const ptrCheckbox = page.locator('input[name="records[0][reverse]"]');
      await ptrCheckbox.waitFor({ state: 'attached' });
      // The checkbox should be visible for A records, but ensure it
      await ptrCheckbox.evaluate(el => { el.style.visibility = 'visible'; });
      await ptrCheckbox.check();
      expect(await ptrCheckbox.isChecked()).toBe(true);

      // Submit
      await page.locator('button[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');

      // Verify no errors
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      // The page must report success, and it must be true: reporting success
      // for a PTR that was never written was a real defect
      await expect(page.locator('body')).toContainText('have been added successfully');
      const ptrExists = await recordExistsInZone(
        page, reverseZoneId,
        '99.2.0.192.in-addr.arpa',
        'PTR',
        `${testHostname}.manager-zone.example.com`
      );
      expect(ptrExists).toBe(true);
    });
  });

  test.describe('PTR record with Add A/AAAA checkbox', () => {
    const testHostname = `match-a-${timestamp}.manager-zone.example.com`;
    const testPtrName = '98';

    test('should create matching A record when adding PTR record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Find reverse zone
      const reverseZoneId = await getTestZoneId(page, 'reverseIPv4');
      expect(reverseZoneId, '2.0.192.in-addr.arpa must exist in the standard test data').toBeTruthy();

      // Find forward zone (to verify later)
      const forwardZoneId = await getTestZoneId(page, 'manager');
      expect(forwardZoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      // Add PTR record with A/AAAA checkbox
      await page.goto(`/zones/${reverseZoneId}/records/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Fill in the PTR record
      await page.locator('select[name="records[0][type]"]').selectOption('PTR');
      await page.locator('input[name="records[0][name]"]').fill(testPtrName);
      await page.locator('input[name="records[0][content]"]').fill(testHostname);

      // Check the A/AAAA checkbox
      const domainCheckbox = page.locator('input[name="records[0][create_domain_record]"]');
      await domainCheckbox.waitFor({ state: 'attached' });
      await domainCheckbox.check();
      expect(await domainCheckbox.isChecked()).toBe(true);

      // Submit
      await page.locator('button[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');

      // Verify no errors
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      // Verify the A record was created in the forward zone
      const aRecordExists = await recordExistsInZone(
        page, forwardZoneId,
        testHostname,
        'A',
        '192.0.2.98'
      );
      expect(aRecordExists).toBe(true);
    });
  });

  test.describe('Checkbox visibility', () => {
    test('should show Add PTR checkbox only for A/AAAA records in forward zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const forwardZoneId = await getTestZoneId(page, 'manager');
      expect(forwardZoneId, 'manager-zone.example.com must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${forwardZoneId}/records/add`);

      const ptrCheckbox = page.locator('input[name="records[0][reverse]"]');
      await ptrCheckbox.waitFor({ state: 'attached' });

      // Select A type - checkbox should be visible
      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await expect(ptrCheckbox).toHaveCSS('visibility', 'visible');

      // Select CNAME - checkbox should be hidden
      await page.locator('select[name="records[0][type]"]').selectOption('CNAME');
      await expect(ptrCheckbox).toHaveCSS('visibility', 'hidden');

      // Select AAAA - checkbox should be visible again
      await page.locator('select[name="records[0][type]"]').selectOption('AAAA');
      await expect(ptrCheckbox).toHaveCSS('visibility', 'visible');
    });

    test('should show Add A/AAAA checkbox in reverse zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const reverseZoneId = await getTestZoneId(page, 'reverseIPv4');
      expect(reverseZoneId, '2.0.192.in-addr.arpa must exist in the standard test data').toBeTruthy();

      await page.goto(`/zones/${reverseZoneId}/records/add`);

      // The A/AAAA checkbox should be visible (always shown in reverse zones)
      const domainCheckbox = page.locator('input[name="records[0][create_domain_record]"]');
      await expect(domainCheckbox).toBeVisible();
    });
  });
});
