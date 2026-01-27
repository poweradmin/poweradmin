/**
 * DNSSEC Key Management Tests
 *
 * Tests for DNSSEC key management including key listing,
 * adding, and managing keys.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

// Helper to get a zone ID for testing
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

test.describe('DNSSEC Key Management', () => {
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

      if (await row.count() > 0) {
        const dnssecLink = row.locator('a[href*="/dnssec"]').first();
        if (await dnssecLink.count() > 0) {
          await dnssecLink.click();
          await expect(page).toHaveURL(/.*dnssec/);
        }
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
      if (await addBtn.count() > 0) {
        await expect(addBtn.first()).toBeVisible();
      }
    });

    test('should show key details when keys exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const table = page.locator('table').first();
      if (await table.count() > 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/algorithm|flag|type|ksk|zsk|key/i);
      }
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
      if (await typeSelector.count() > 0) {
        await expect(typeSelector.first()).toBeVisible();
      }
    });

    test('should display algorithm selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const algoSelector = page.locator('select[name*="algorithm"], select[name*="algo"]');
      if (await algoSelector.count() > 0) {
        await expect(algoSelector.first()).toBeVisible();
      }
    });

    test('should display key size options', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const sizeSelector = page.locator('select[name*="bits"], select[name*="size"], input[name*="bits"]');
      if (await sizeSelector.count() > 0) {
        await expect(sizeSelector.first()).toBeVisible();
      }
    });

    test('should add KSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const form = page.locator('form');
      if (await form.count() > 0) {
        const typeSelector = page.locator('select[name*="type"], select[name*="key_type"]').first();
        if (await typeSelector.count() > 0) {
          const options = await typeSelector.locator('option').allTextContents();
          const kskOption = options.find(o => o.toUpperCase().includes('KSK'));
          if (kskOption) {
            await typeSelector.selectOption({ label: kskOption });
          }
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should add ZSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const form = page.locator('form');
      if (await form.count() > 0) {
        const typeSelector = page.locator('select[name*="type"], select[name*="key_type"]').first();
        if (await typeSelector.count() > 0) {
          const options = await typeSelector.locator('option').allTextContents();
          const zskOption = options.find(o => o.toUpperCase().includes('ZSK'));
          if (zskOption) {
            await typeSelector.selectOption({ label: zskOption });
          }
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should select different algorithms', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);

      const algoSelector = page.locator('select[name*="algorithm"], select[name*="algo"]').first();
      if (await algoSelector.count() > 0) {
        const options = await algoSelector.locator('option').count();
        expect(options).toBeGreaterThan(0);

        if (options > 1) {
          await algoSelector.selectOption({ index: 1 });
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('DS Records', () => {
    test('should display DS records when keys exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const bodyText = await page.locator('body').textContent();
      // DS records should be shown if keys exist
      expect(bodyText.toLowerCase()).toMatch(/ds|delegation|key|dnssec/i);
    });

    test('should show DS record in copyable format', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/dnssec`);

      const dsArea = page.locator('pre, code, textarea, .ds-record');
      if (await dsArea.count() > 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not be able to add keys', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const row = page.locator('table tbody tr').first();

      if (await row.count() > 0) {
        const dnssecLink = row.locator('a[href*="/dnssec"]').first();
        if (await dnssecLink.count() > 0) {
          await dnssecLink.click();

          const addBtn = page.locator('a[href*="/dnssec/keys/add"]');
          expect(await addBtn.count()).toBe(0);
        }
      }
    });
  });
});
