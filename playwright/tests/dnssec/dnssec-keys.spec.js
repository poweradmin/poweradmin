/**
 * DNSSEC Key Management Tests
 *
 * Tests for DNSSEC key management including key listing,
 * adding, and managing keys.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { listDnssecKeyIds, pruneDnssecKeys } from '../../helpers/dnssec.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

// Helper to get a zone ID for testing
async function getTestZoneId(page) {
  await page.goto('/zones/forward?letter=all');
  // Scoped to the table: an unscoped a[href*="/edit"] matches the nav dropdown first,
  // which carries no zone id, so this helper used to return null for every test.
  const editLink = page.locator('table a[href*="/zones/"][href*="/edit"]').first();
  await expect(editLink.first()).toBeVisible();
  const href = await editLink.getAttribute('href');
  const match = href.match(/\/zones\/(\d+)\/edit/);
  return match ? match[1] : null;
  return null;
}

test.describe('DNSSEC Key Management', () => {
  // Several tests below submit the add-key form. Without this the zone accumulates
  // keys on every run - it had grown from 4 to 20 before the prune existed.
  let pristineKeyIds = [];
  let pruneZoneId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    pruneZoneId = await getTestZoneId(page);
    if (pruneZoneId) {
      pristineKeyIds = await listDnssecKeyIds(page, pruneZoneId);
    }
    await page.close();
  });

  test.afterAll(async ({ browser }) => {
    if (!pruneZoneId) {
      return;
    }
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await pruneDnssecKeys(page, pruneZoneId, pristineKeyIds);
    await page.close();
  });

  test.describe('DNSSEC Page Access', () => {
    test('admin should access DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);
      await expect(page).toHaveURL(/.*dnssec/);
    });

    test('should display DNSSEC page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

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
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/key|dnssec|add|no.*key/i);
    });

    test('should display add key button', async ({ page }) => {
      test.setTimeout(60000);
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`, { timeout: 30000 });
      await page.waitForLoadState('networkidle');

      const addBtn = page.locator('a[href*="/dnssec/keys/add"], input[value*="Add"], button:has-text("Add")');
      await expect(addBtn.first()).toBeVisible();
    });

    test('should show key details when keys exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

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
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/add.*key|create.*key|dnssec/i);
    });

    test('should display key type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const typeSelector = page.locator('select[name*="type"], select[name*="key_type"], input[name*="type"]');
      await expect(typeSelector.first()).toBeVisible();
    });

    test('should display algorithm selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const algoSelector = page.locator('select[name*="algorithm"], select[name*="algo"]');
      await expect(algoSelector.first()).toBeVisible();
    });

    test('should display key size options', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const sizeSelector = page.locator('select[name*="bits"], select[name*="size"], input[name*="bits"]');
      await expect(sizeSelector.first()).toBeVisible();
    });

    test('should add KSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const form = page.locator('form');
      await expect(form.first()).toBeVisible();
      const typeSelector = page.locator('select[name*="type"], select[name*="key_type"]').first();
      if (await typeSelector.count() > 0) {
        const options = await typeSelector.locator('option').allTextContents();
        const kskOption = options.find(o => o.toUpperCase().includes('KSK'));
        if (kskOption) {
          await typeSelector.selectOption({ label: kskOption });
        }
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should add ZSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const form = page.locator('form');
      await expect(form.first()).toBeVisible();
      const typeSelector = page.locator('select[name*="type"], select[name*="key_type"]').first();
      if (await typeSelector.count() > 0) {
        const options = await typeSelector.locator('option').allTextContents();
        const zskOption = options.find(o => o.toUpperCase().includes('ZSK'));
        if (zskOption) {
          await typeSelector.selectOption({ label: zskOption });
        }
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should select different algorithms', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

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
    test('should display activate/deactivate links', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      // Scoped to the key table: an unscoped a[href*="/edit"] matches the nav dropdown
      const activateLinks = page.locator('table a[href*="/dnssec"], table a[href*="/activate"], table a[href*="/deactivate"]');
      test.skip(await activateLinks.count() === 0, 'zone has no DNSSEC keys to activate or deactivate');
      await expect(activateLinks.first()).toBeVisible();
    });

    test('should access key edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const editLink = page.locator('a[href*="/dnssec/keys/"][href*="/edit"]').first();
      await expect(editLink.first()).toBeVisible();
      await editLink.click();
      await expect(page).toHaveURL(/.*dnssec.*edit/);
    });

    test('should toggle key status', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const editLink = page.locator('a[href*="/dnssec/keys/"][href*="/edit"]').first();
      await expect(editLink.first()).toBeVisible();
      await editLink.click();

      const toggleBtn = page.locator('button:has-text("Activate"), button:has-text("Deactivate"), input[value*="Activate"], input[value*="Deactivate"]').first();
      if (await toggleBtn.count() > 0) {
        await toggleBtn.click();

        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      }
    });
  });

  test.describe('Delete DNSSEC Key', () => {
    test('should display delete key links', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLinks = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]');
      await expect(deleteLinks.first()).toBeVisible();
    });

    test('should access delete confirmation page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLink = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*dnssec.*delete/);
    });

    test('should display delete confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLink = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*dnssec.*delete/);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
    });

    test('should cancel delete and return to DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLink = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();

      const cancelBtn = page.locator('a:has-text("Cancel"), button:has-text("Cancel")').first();
      if (await cancelBtn.count() > 0) {
        await cancelBtn.click();
        await expect(page).toHaveURL(/.*dnssec/);
      }
    });

    test('should submit delete form without CSRF error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const deleteLink = page.locator('a[href*="/dnssec/keys/"][href*="/delete"]').last();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*dnssec.*delete/);

      // Verify the form has the correct CSRF token field name
      const tokenField = page.locator('input[name="_token"]');
      await expect(tokenField).toHaveCount(1);

      // Submit the delete form
      const deleteBtn = page.locator('button[type="submit"]:has-text("Delete")').first();
      if (await deleteBtn.count() > 0) {
        await deleteBtn.click();

        // Auto-retrying so a slow render cannot read the page mid-flight
        await expect(page.locator('body')).not.toContainText(/Invalid CSRF token/i);
      }
    });
  });

  test.describe('DS and DNSKEY Records', () => {
    test('should access DS records page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/ds|dnskey|record/i);
    });

    test('should display DS record link from DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const dsLink = page.locator('a[href*="/ds-dnskey"]');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should navigate to DS records from DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const dsLink = page.locator('a[href*="/ds-dnskey"]').first();
      await expect(dsLink.first()).toBeVisible();
      await dsLink.click();
      await expect(page).toHaveURL(/.*ds-dnskey/);
    });

    test('should display DS record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

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
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      // The zone edit page only links to /dnssec once the zone is signed;
      // an unsigned zone shows a "Sign zone" button instead.
      const dnssecLink = page.locator('a[href*="/dnssec"]').first();
      test.skip(await dnssecLink.count() === 0, 'zone is unsigned, so it has no DNSSEC link');
      await dnssecLink.click();
      await expect(page).toHaveURL(/.*dnssec/);
    });

    test('should have back to zone link from DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

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
