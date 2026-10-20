/**
 * Matching Record Creation Tests (Issue #1104)
 *
 * Tests for the "Add PTR" and "Add A/AAAA" checkbox functionality
 * that creates matching records in corresponding zones.
 *
 * - A record -> matching PTR record in reverse zone
 * - PTR record -> matching A record in forward zone
 *
 * The writing tests run against a throwaway forward zone (tempZone) and a
 * throwaway /24 reverse zone under 10.in-addr.arpa. The seeded zones
 * manager-zone.example.com and 2.0.192.in-addr.arpa are only read.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { createZone, deleteZoneById, getTestZoneId } from '../../helpers/zones.js';
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

  // Only record rows count: the filter box above also carries the searched value
  const contentInput = page.locator(`input[name^="record["][name$="][content]"][value="${recordContent}"]`);
  return await contentInput.count() > 0;
}

// Random /24 under 10.0.0.0/8 so parallel runs never share a reverse zone
function randomReverseZone() {
  const b = 1 + Math.floor(Math.random() * 254);
  const c = 1 + Math.floor(Math.random() * 254);
  return { name: `${c}.${b}.10.in-addr.arpa`, ip: (host) => `10.${b}.${c}.${host}` };
}

// Reverse zones created by the running test; afterEach deletes them even when the
// test timed out, which a try/finally inside the test body would not survive
const createdReverseZones = [];

// Create a throwaway reverse zone, run fn(zoneId, zone); cleanup happens in afterEach
async function withReverseZone(page, fn) {
  const zone = randomReverseZone();
  const zoneId = await createZone(page, zone.name);
  createdReverseZones.push(zoneId);
  return await fn(zoneId, zone);
}

test.afterEach(async ({ browser, baseURL }, testInfo) => {
  const ids = createdReverseZones.splice(0);
  if (ids.length === 0) {
    return;
  }
  testInfo.setTimeout(testInfo.timeout + 60_000);
  const context = await browser.newContext({ baseURL });
  try {
    const page = await context.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password, 3, { fresh: true });
    for (const id of ids) {
      await deleteZoneById(page, id);
    }
  } finally {
    await context.close();
  }
});

test.describe('Matching Record Creation (Issue #1104)', () => {
  const timestamp = Date.now();

  test.describe('A record with Add PTR checkbox', () => {
    const testHostname = `match-ptr-${timestamp}`;

    test('should create matching PTR record when adding A record', async ({ page, tempZone }) => {
      test.slow(); // creates throwaway zones on top of the test itself
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await withReverseZone(page, async (reverseZoneId, reverse) => {
        const forwardZoneId = tempZone.id;
        const testIP = reverse.ip(99);

        await page.goto(`/zones/${forwardZoneId}/records/add`);
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);

        await page.locator('select[name="records[0][type]"]').selectOption('A');
        await page.locator('input[name="records[0][name]"]').fill(testHostname);
        await page.locator('input[name="records[0][content]"]').fill(testIP);

        // Check the PTR checkbox (make visible first since JS hides it for non-A types)
        const ptrCheckbox = page.locator('input[name="records[0][reverse]"]');
        await ptrCheckbox.waitFor({ state: 'attached' });
        await ptrCheckbox.evaluate(el => { el.style.visibility = 'visible'; });
        await ptrCheckbox.check();
        expect(await ptrCheckbox.isChecked()).toBe(true);

        await page.locator('button[type="submit"]').first().click();
        await page.waitForLoadState('domcontentloaded');

        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

        // The page must report success, and it must be true: reporting success
        // for a PTR that was never written was a real defect
        await expect(page.locator('body')).toContainText('have been added successfully');
        const ptrExists = await recordExistsInZone(
          page, reverseZoneId,
          `99.${reverse.name}`,
          'PTR',
          `${testHostname}.${tempZone.name}`
        );
        expect(ptrExists).toBe(true);
      });
    });
  });

  test.describe('PTR record with Add A/AAAA checkbox', () => {
    const testLabel = `match-a-${timestamp}`;
    const testPtrName = '98';

    test('should create matching A record when adding PTR record', async ({ page, tempZone }) => {
      test.slow(); // creates throwaway zones on top of the test itself
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const hostname = `${testLabel}.${tempZone.name}`;

      await withReverseZone(page, async (reverseZoneId, reverse) => {
        const forwardZoneId = tempZone.id;

        await page.goto(`/zones/${reverseZoneId}/records/add`);
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);

        await page.locator('select[name="records[0][type]"]').selectOption('PTR');
        await page.locator('input[name="records[0][name]"]').fill(testPtrName);
        await page.locator('input[name="records[0][content]"]').fill(hostname);

        const domainCheckbox = page.locator('input[name="records[0][create_domain_record]"]');
        await domainCheckbox.waitFor({ state: 'attached' });
        await domainCheckbox.check();
        expect(await domainCheckbox.isChecked()).toBe(true);

        await page.locator('button[type="submit"]').first().click();
        await page.waitForLoadState('domcontentloaded');

        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

        // Verify the A record was created in the forward zone
        const aRecordExists = await recordExistsInZone(
          page, forwardZoneId,
          hostname,
          'A',
          reverse.ip(98)
        );
        expect(aRecordExists).toBe(true);
      });
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
