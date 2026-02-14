import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensureTestZoneExists, zones } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNSSEC Key Lifecycle', () => {
  // Use admin-zone.example.com for DNSSEC testing (owned by admin user)
  const testZoneName = zones.admin.name;

  test.describe('Key Generation', () => {
    test('should access DNSSEC page for zone', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnssec|key|secure/i);
    });

    test('should display add key button', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const addBtn = page.locator('a[href*="dnssec_add_key"], input[value*="Add"], button:has-text("Add")');
      if (await addBtn.count() > 0) {
        await expect(addBtn.first()).toBeVisible();
      }
    });

    test('should access add key page', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display CSK info alert on add key page', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const cskInfoAlert = page.locator('#csk-info-alert');
      await expect(cskInfoAlert).toBeVisible();
      await expect(cskInfoAlert).toContainText('PowerDNS 4.0');
      await expect(cskInfoAlert).toContainText('CSK');
    });

    test('should display key type selector', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const typeSelector = page.locator('select[name*="type"], input[name*="type"], input[type="radio"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should display algorithm selector', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const algoSelector = page.locator('select[name*="algo"], select[name*="algorithm"]');
      if (await algoSelector.count() > 0) {
        await expect(algoSelector.first()).toBeVisible();
      }
    });

    test('should display key size selector', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const sizeSelector = page.locator('select[name*="size"], select[name*="bits"], input[name*="size"]');
      if (await sizeSelector.count() > 0) {
        await expect(sizeSelector.first()).toBeVisible();
      }
    });

    test('should generate KSK key', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const kskRadio = page.locator('input[value="ksk"], input[value="KSK"]');
      if (await kskRadio.count() > 0) {
        await kskRadio.first().check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should generate ZSK key', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

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
    test('should display key activation status', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/active|inactive|status|key/i);
    });

    test('should activate inactive key', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const activateLink = page.locator('a[href*="dnssec_activate"], a:has-text("Activate")').first();
      if (await activateLink.count() > 0) {
        await activateLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should deactivate active key', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deactivateLink = page.locator('a[href*="dnssec_deactivate"], a:has-text("Deactivate")').first();
      if (await deactivateLink.count() > 0) {
        await deactivateLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('DS Records', () => {
    test('should display DS records section', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display DNSKEY records', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnskey|ds|key/i);
    });

    test('should show DS record formats', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      // DS records typically show algorithm and digest type
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Key Deletion', () => {
    test('should access delete key confirmation', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/dnssec_delete_key/);
      }
    });

    test('should display delete confirmation message', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|remove/i);
      }
    });

    test('should cancel key deletion', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const cancelBtn = page.locator('a:has-text("Cancel"), button:has-text("Cancel")').first();
        if (await cancelBtn.count() > 0) {
          await cancelBtn.click();
          await expect(page).toHaveURL(/dnssec/);
        }
      }
    });

    test('should delete key without CSRF error', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').last();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/dnssec_delete_key/);

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
    test('admin should access DNSSEC settings', async ({ adminPage: page }) => {
      const zoneId = await ensureTestZoneExists(page, 'admin');
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      // Use more specific pattern to avoid matching dropdown menu text
      expect(bodyText).not.toMatch(/you do not have|access denied|not authorized/i);
    });

    test('manager should access DNSSEC for own zones', async ({ managerPage: page }) => {
      test.setTimeout(60000);

      await page.goto('/index.php?page=list_forward_zones&letter=all', { timeout: 30000 });
      await page.waitForLoadState('networkidle');

      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() === 0) {
        test.skip('No zones available for manager user');
        return;
      }

      const href = await editLink.getAttribute('href');
      const match = href?.match(/id=(\d+)/);
      if (match) {
        await page.goto(`/index.php?page=dnssec&id=${match[1]}`, { timeout: 30000 });
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('viewer should have appropriate DNSSEC access', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const zoneIdMatch = href?.match(/id=(\d+)/);
        if (zoneIdMatch) {
          await page.goto(`/index.php?page=dnssec&id=${zoneIdMatch[1]}`);
          // Verify page loads without fatal errors
          // Viewer access level depends on configuration
          const bodyText = await page.locator('body').textContent() || '';
          // Page should either show DNSSEC info, access denied, or error (not fatal crash)
          expect(bodyText).not.toMatch(/fatal|exception/i);
          // Page should contain zone-related content
          expect(bodyText.toLowerCase()).toMatch(/dnssec|zone|key|denied|not authorized/i);
        }
      }
    });
  });

  // No cleanup needed - we use existing test zones and don't create new ones
});
