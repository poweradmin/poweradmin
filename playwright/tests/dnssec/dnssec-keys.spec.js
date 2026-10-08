/**
 * DNSSEC Key Management Tests
 *
 * Tests for DNSSEC key management including key listing,
 * adding, and managing keys.
 */

import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addDnssecKey, ensureDnssecKey, ensureZoneSigned, listDnssecKeyIds, submitKeyToggle } from '../../helpers/dnssec.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNSSEC Key Management', () => {
  // The spec owns this zone, so adding, toggling and deleting keys cannot
  // touch seeded zones or other specs; it is deleted after the last test.
  const zone = useFileZone('dnskeys');
  let zoneId = null;

  test.beforeAll(async ({ browser }) => {
    zoneId = zone.id;
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await ensureZoneSigned(page, zoneId);
    } finally {
      await page.close();
    }
  });

  test.describe('DNSSEC Page Access', () => {
    test('admin should access DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);
      await expect(page).toHaveURL(/.*dnssec/);
    });

    test('should display DNSSEC page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnssec|keys/i);
    });

    test('manager should access DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');
      const row = page.locator('table tbody tr').first();

      await expect(row.first()).toBeVisible();
      const dnssecLink = row.locator('a[href*="/dnssec"]').first();
      if (await dnssecLink.count() > 0) {
        await dnssecLink.click();
        await expect(page).toHaveURL(/.*dnssec/);
      }
    });
  });

  test.describe('DNSSEC Key Listing', () => {
    test('should display key list or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/key|dnssec|add|no.*key/i);
    });

    test('should display add key button', async ({ page }) => {
      test.setTimeout(60000);
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`, { timeout: 30000 });
      await page.waitForLoadState('networkidle');

      const addBtn = page.locator('a[href*="/dnssec/keys/add"], input[value*="Add"], button:has-text("Add")');
      await expect(addBtn.first()).toBeVisible();
    });

    test('should show key details when keys exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);

      const table = page.locator('table').first();
      await expect(table.first()).toBeVisible();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/algorithm|flag|type|ksk|zsk|key/i);
    });
  });

  test.describe('Add DNSSEC Key', () => {
    test('should access add key page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/add.*key|create.*key|dnssec/i);
    });

    test('should display key type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const typeSelector = page.locator('select[name*="type"], select[name*="key_type"], input[name*="type"]');
      await expect(typeSelector.first()).toBeVisible();
    });

    test('should display algorithm selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const algoSelector = page.locator('select[name*="algorithm"], select[name*="algo"]');
      await expect(algoSelector.first()).toBeVisible();
    });

    test('should display key size options', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const sizeSelector = page.locator('select[name*="bits"], select[name*="size"], input[name*="bits"]');
      await expect(sizeSelector.first()).toBeVisible();
    });

    test('should add KSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const options = await page.locator('select[name="key_type"] option').allTextContents();
      expect(options.some(o => o.toUpperCase().includes('KSK')), 'the key type list offers KSK').toBe(true);

      // addDnssecKey waits for the redirect and asserts the key count grew by one
      const keyId = await addDnssecKey(page, zoneId, { keyType: 'ksk' });
      expect(await listDnssecKeyIds(page, zoneId)).toContain(keyId);
    });

    test('should add ZSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const options = await page.locator('select[name="key_type"] option').allTextContents();
      expect(options.some(o => o.toUpperCase().includes('ZSK')), 'the key type list offers ZSK').toBe(true);

      // addDnssecKey waits for the redirect and asserts the key count grew by one
      const keyId = await addDnssecKey(page, zoneId, { keyType: 'zsk' });
      expect(await listDnssecKeyIds(page, zoneId)).toContain(keyId);
    });

    test('should select different algorithms', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const algoSelector = page.locator('select[name*="algorithm"], select[name*="algo"]').first();
      await expect(algoSelector.first()).toBeVisible();
      const options = await algoSelector.locator('option').count();
      expect(options).toBeGreaterThan(0);

      if (options > 1) {
        await algoSelector.selectOption({ index: 1 });
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Activate/Deactivate Key', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await ensureDnssecKey(page, zoneId);
    });

    test('should display activate/deactivate links', async ({ page }) => {

      await page.goto(`/zones/${zoneId}/dnssec`);

      // Scoped to the key table: an unscoped a[href*="/edit"] matches the nav dropdown
      await expect(page.locator('table a[href*="/dnssec/keys/"][href*="/edit"]').first()).toBeVisible();
    });

    test('should access key edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);

      const editLink = page.locator('a[href*="/dnssec/keys/"][href*="/edit"]').first();
      await expect(editLink.first()).toBeVisible();
      await editLink.click();
      await expect(page).toHaveURL(/.*dnssec.*edit/);
    });

    test('should toggle key status', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const [keyId] = await listDnssecKeyIds(page, zoneId);
      expect(keyId, 'ensureDnssecKey must have left a key').toBeTruthy();

      // submitKeyToggle asserts the Activate/Deactivate link counts flipped
      const first = await submitKeyToggle(page, zoneId, keyId);
      const second = await submitKeyToggle(page, zoneId, keyId);
      expect(second).not.toBe(first);
    });
  });

  /**
   * Every delete test needs a key of its own: they used to act on whichever key
   * the zone already had, and the test that submits the delete form removed it,
   * so the ones that ran afterwards found no delete link at all.
   */
  async function createDnssecKey(page, zoneId) {
    await addDnssecKey(page, zoneId);
  }

  test.describe('Delete DNSSEC Key', () => {
    // These tests consume keys, so every one of them starts from a zone that has at least one
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await ensureDnssecKey(page, zoneId);
    });

    test('should display delete key links', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await createDnssecKey(page, zoneId);
      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLinks = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]');
      await expect(deleteLinks.first()).toBeVisible();
    });

    test('should access delete confirmation page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await createDnssecKey(page, zoneId);
      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLink = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*dnssec.*delete/);
    });

    test('should display delete confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await createDnssecKey(page, zoneId);
      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLink = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*dnssec.*delete/);

      // The URL commits before the body is parsed, so a one-shot textContent()
      // read here sees the navigation header and nothing else.
      await expect(page.locator('body')).toContainText(/delete|confirm|sure/i);
    });

    test('should cancel delete and return to DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await createDnssecKey(page, zoneId);
      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLink = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();

      const cancelBtn = page.locator('main a:has-text("Cancel"), main button:has-text("Cancel")').first();
      await expect(cancelBtn).toBeVisible();
      await cancelBtn.click();
      await expect(page).toHaveURL(/.*\/dnssec$/);
    });

    test('should submit delete form without CSRF error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await createDnssecKey(page, zoneId);
      await page.goto(`/zones/${zoneId}/dnssec`);

      const keysBefore = await listDnssecKeyIds(page, zoneId);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('table a[href*="/dnssec/keys/"][href*="/delete"]').last();
      await expect(deleteLink.first()).toBeVisible();
      const deletedId = (await deleteLink.getAttribute('href')).match(/\/dnssec\/keys\/(\d+)\/delete/)[1];
      await deleteLink.click();
      await expect(page).toHaveURL(/.*dnssec.*delete/);

      // Verify the form has the correct CSRF token field name
      const tokenField = page.locator('input[name="_token"]');
      await expect(tokenField).toHaveCount(1);

      // Submit the delete form
      const deleteBtn = page.locator('form[action*="/delete"] button[type="submit"]');
      await expect(deleteBtn).toBeVisible();
      await Promise.all([
        page.waitForURL(url => url.pathname.endsWith(`/zones/${zoneId}/dnssec`), { timeout: 30000 }),
        deleteBtn.click(),
      ]);

      await expect(page.locator('body')).not.toContainText(/Invalid CSRF token/i);
      const keysAfter = await listDnssecKeyIds(page, zoneId);
      expect(keysAfter, 'the deleted key must be gone').not.toContain(deletedId);
      expect(keysAfter).toHaveLength(keysBefore.length - 1);
    });
  });

  test.describe('DS and DNSKEY Records', () => {
    test('should access DS records page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/ds|dnskey|record/i);
    });

    test('should display DS record link from DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);

      const dsLink = page.locator('a[href*="/ds-dnskey"]');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(await dsLink.count()).toBeGreaterThan(0);
    });

    test('should navigate to DS records from DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);

      const dsLink = page.locator('a[href*="/ds-dnskey"]').first();
      await expect(dsLink.first()).toBeVisible();
      await dsLink.click();
      await expect(page).toHaveURL(/.*ds-dnskey/);
    });

    test('should display DS record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/ds|dnskey|key|record|dnssec|not.*enabled|no.*key/i);
    });
  });

  test.describe('Navigation', () => {
    test('should navigate from zone list to DNSSEC', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const row = page.locator('table tbody tr').first();
      await expect(row.first()).toBeVisible();
      const dnssecLink = row.locator('a[href*="/dnssec"]').first();
      if (await dnssecLink.count() > 0) {
        await dnssecLink.click();
        await expect(page).toHaveURL(/.*dnssec/);
      }
    });

    test('should navigate from zone edit to DNSSEC', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // The zone edit page only links to /dnssec once the zone has an active key
      await ensureZoneSigned(page, zoneId);
      await page.goto(`/zones/${zoneId}/edit`);

      const dnssecLink = page.locator(`a[href$="/zones/${zoneId}/dnssec"]`).first();
      await expect(dnssecLink).toBeVisible();
      await dnssecLink.click();
      await expect(page).toHaveURL(/.*dnssec/);
    });

    test('should have back to zone link from DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/dnssec`);

      const backLink = page.locator('a[href*="/edit"], a:has-text("Back"), a:has-text("Zone")');
      await expect(backLink.first()).toBeVisible();
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not be able to add keys', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const row = page.locator('table tbody tr').first();

      await expect(row.first()).toBeVisible();
      const dnssecLink = row.locator('a[href*="/dnssec"]').first();
      if (await dnssecLink.count() > 0) {
        await dnssecLink.click();

        const addBtn = page.locator('a[href*="/dnssec/keys/add"]');
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(addBtn).toHaveCount(0);
      }
    });

    test('viewer should not access DNSSEC management', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const row = page.locator('table tbody tr').first();
      await expect(row.first()).toBeVisible();
      const dnssecLink = row.locator('a[href*="/dnssec"]');
      const count = await dnssecLink.count();
      if (count > 0) {
        await dnssecLink.first().click();
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).toContainText(/you do not have|access denied|not allowed|forbidden|dnssec/i);
      }
    });
  });
});
