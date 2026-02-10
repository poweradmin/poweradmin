/**
 * DNSSEC Key Lifecycle Tests
 *
 * Tests for DNSSEC key generation, activation, and deletion lifecycle.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNSSEC Key Lifecycle', () => {
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

  test.describe('Key Generation', () => {
    test('should access DNSSEC page for zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnssec|key|secure/i);
    });

    test('should display add key button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const addBtn = page.locator('a[href*="/dnssec/add"], input[value*="Add"], button:has-text("Add")');
      if (await addBtn.count() > 0) {
        await expect(addBtn.first()).toBeVisible();
      }
    });

    test('should access add key page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display key type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/add`);
      const typeSelector = page.locator('select[name*="type"], input[name*="type"], input[type="radio"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should display algorithm selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/add`);
      const algoSelector = page.locator('select[name*="algo"], select[name*="algorithm"]');
      if (await algoSelector.count() > 0) {
        await expect(algoSelector.first()).toBeVisible();
      }
    });

    test('should display key size selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/add`);
      const sizeSelector = page.locator('select[name*="size"], select[name*="bits"], input[name*="size"]');
      if (await sizeSelector.count() > 0) {
        await expect(sizeSelector.first()).toBeVisible();
      }
    });

    test('should generate KSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/add`);

      const kskRadio = page.locator('input[value="ksk"], input[value="KSK"]');
      if (await kskRadio.count() > 0) {
        await kskRadio.first().check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should generate ZSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/add`);

      const zskRadio = page.locator('input[value="zsk"], input[value="ZSK"]');
      if (await zskRadio.count() > 0) {
        await zskRadio.first().check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Key Activation', () => {
    test('should display key activation status', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/active|inactive|status|key/i);
    });

    test('should activate inactive key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const activateLink = page.locator('a[href*="/activate"], a:has-text("Activate")').first();
      if (await activateLink.count() > 0) {
        await activateLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should deactivate active key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deactivateLink = page.locator('a[href*="/deactivate"], a:has-text("Deactivate")').first();
      if (await deactivateLink.count() > 0) {
        await deactivateLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('DS Records', () => {
    test('should display DS records section', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display DNSKEY records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnskey|ds|key/i);
    });

    test('should show DS record formats', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Key Deletion', () => {
    test('should access delete key confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/.*dnssec.*delete/);
      }
    });

    test('should display delete confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|remove/i);
      }
    });

    test('should cancel key deletion', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const cancelBtn = page.locator('a:has-text("Cancel"), button:has-text("Cancel")').first();
        if (await cancelBtn.count() > 0) {
          await cancelBtn.click();
          await expect(page).toHaveURL(/.*dnssec/);
        }
      }
    });

    test('should delete key without CSRF error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('a[href*="/delete"]').last();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/.*dnssec.*delete/);

        // Verify the form has the correct CSRF token field name
        const tokenField = page.locator('input[name="_token"]');
        expect(await tokenField.count()).toBe(1);

        // Submit the delete form
        const deleteBtn = page.locator('button[type="submit"]:has-text("Delete")').first();
        if (await deleteBtn.count() > 0) {
          await deleteBtn.click();
          await page.waitForLoadState('networkidle');

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/Invalid CSRF token/i);
        }
      }
    });
  });

  test.describe('DNSSEC Permissions', () => {
    test('admin should access DNSSEC settings', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available for DNSSEC test');
        return;
      }
      await page.goto(`/zones/${zoneId}/dnssec`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/you do not have|access denied|not authorized/i);
    });

    test('manager should access DNSSEC for own zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() === 0) {
        test.skip('No zones available for manager user');
        return;
      }

      const href = await editLink.getAttribute('href');
      const match = href?.match(/\/zones\/(\d+)\/edit/);
      if (match) {
        await page.goto(`/zones/${match[1]}/dnssec`);
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('viewer should have appropriate DNSSEC access', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const zoneIdMatch = href?.match(/\/zones\/(\d+)\/edit/);
        if (zoneIdMatch) {
          await page.goto(`/zones/${zoneIdMatch[1]}/dnssec`);
          const bodyText = await page.locator('body').textContent() || '';
          expect(bodyText).not.toMatch(/fatal|exception/i);
          expect(bodyText.toLowerCase()).toMatch(/dnssec|zone|key|denied|not authorized/i);
        }
      }
    });
  });
});
