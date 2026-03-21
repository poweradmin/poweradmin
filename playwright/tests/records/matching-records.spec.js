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
import users from '../../fixtures/users.json' assert { type: 'json' };

// Tests run serially to avoid database conflicts
test.describe.configure({ mode: 'serial' });

// Helper to find a zone ID by name
async function getZoneIdByName(page, zoneName, zoneType) {
  const listUrl = zoneType === 'reverse' ? '/zones/reverse?letter=all' : '/zones/forward?letter=all';
  await page.goto(listUrl);

  const zoneLink = page.locator(`a[href*="/edit"]:has-text("${zoneName}")`).first();
  if (await zoneLink.count() > 0) {
    const href = await zoneLink.getAttribute('href');
    const match = href.match(/\/zones\/(\d+)\/edit/);
    return match ? match[1] : null;
  }
  return null;
}

// Check if a record with the given type and content exists in a zone
async function recordExistsInZone(page, zoneId, recordName, recordType, recordContent) {
  await page.goto(`/zones/${zoneId}/edit`);
  const matchingRow = page.locator('tr')
    .filter({ hasText: recordType })
    .filter({ hasText: recordContent });
  return await matchingRow.count() > 0;
}

test.describe('Matching Record Creation (Issue #1104)', () => {
  const timestamp = Date.now();

  test.describe('A record with Add PTR checkbox', () => {
    const testHostname = `match-ptr-${timestamp}`;
    const testIP = '192.0.2.99';

    test('should create matching PTR record when adding A record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Find forward zone
      const forwardZoneId = await getZoneIdByName(page, 'manager-zone.example.com', 'forward');
      if (!forwardZoneId) {
        test.skip('Forward zone manager-zone.example.com not found - load test data first');
        return;
      }

      // Find reverse zone (to verify later)
      const reverseZoneId = await getZoneIdByName(page, '2.0.192.in-addr.arpa', 'reverse');
      if (!reverseZoneId) {
        test.skip('Reverse zone 2.0.192.in-addr.arpa not found - load test data first');
        return;
      }

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
      const resultText = await page.locator('body').textContent();
      expect(resultText).not.toMatch(/fatal|exception/i);

      // Check success message mentions matching record
      const hasMatchingMessage = resultText.includes('matching') || resultText.includes('PTR');
      // Even without specific message, verify the PTR was created
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
      const reverseZoneId = await getZoneIdByName(page, '2.0.192.in-addr.arpa', 'reverse');
      if (!reverseZoneId) {
        test.skip('Reverse zone 2.0.192.in-addr.arpa not found - load test data first');
        return;
      }

      // Find forward zone (to verify later)
      const forwardZoneId = await getZoneIdByName(page, 'manager-zone.example.com', 'forward');
      if (!forwardZoneId) {
        test.skip('Forward zone manager-zone.example.com not found - load test data first');
        return;
      }

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
      const resultText = await page.locator('body').textContent();
      expect(resultText).not.toMatch(/fatal|exception/i);

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

      const forwardZoneId = await getZoneIdByName(page, 'manager-zone.example.com', 'forward');
      if (!forwardZoneId) {
        test.skip('Forward zone not found');
        return;
      }

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

      const reverseZoneId = await getZoneIdByName(page, '2.0.192.in-addr.arpa', 'reverse');
      if (!reverseZoneId) {
        test.skip('Reverse zone not found');
        return;
      }

      await page.goto(`/zones/${reverseZoneId}/records/add`);

      // The A/AAAA checkbox should be visible (always shown in reverse zones)
      const domainCheckbox = page.locator('input[name="records[0][create_domain_record]"]');
      await expect(domainCheckbox).toBeVisible();
    });
  });
});
