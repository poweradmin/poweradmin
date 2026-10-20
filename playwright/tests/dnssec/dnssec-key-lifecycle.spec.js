/**
 * DNSSEC Key Lifecycle Tests
 *
 * Tests for DNSSEC key generation, activation, and deletion lifecycle.
 */

import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addDnssecKey, ensureDnssecKey, listDnssecKeyIds, submitKeyToggle } from '../../helpers/dnssec.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNSSEC Key Lifecycle', () => {
  // The zone is this spec's own, so every key on it is under its control
  // and it is deleted (with its keys) after the last test.
  const zone = useFileZone('dnskeylife');
  let zoneId = null;

  test.beforeAll(async ({ browser }) => {
    zoneId = zone.id;
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await ensureDnssecKey(page, zoneId);
    } finally {
      await page.close();
    }
  });

  // Id of a key whose toggle link shows the given icon (untranslated), or null when none does
  async function keyIdWithToggle(page, iconClass) {
    await page.goto(`/zones/${zoneId}/dnssec`);
    const hrefs = await page.locator(`table a[href*="/dnssec/keys/"][href$="/edit"] i.${iconClass}`).evaluateAll(
      icons => icons.map(i => i.closest('a').getAttribute('href'))
    );
    return hrefs[0]?.match(/\/dnssec\/keys\/(\d+)\//)?.[1] ?? null;
  }

  // The add-key form creates inactive keys, so flip one when the wanted state is missing.
  async function keyIdInState(page, wantActive) {
    // pause-circle = active key (offers Deactivate), play-circle = inactive key (offers Activate)
    const wanted = wantActive ? 'bi-pause-circle' : 'bi-play-circle';
    await ensureDnssecKey(page, zoneId);
    let keyId = await keyIdWithToggle(page, wanted);
    if (!keyId) {
      const [anyKey] = await listDnssecKeyIds(page, zoneId);
      await submitKeyToggle(page, zoneId, anyKey);
      keyId = await keyIdWithToggle(page, wanted);
    }
    expect(keyId, `a key showing ${wanted} must exist`).toBeTruthy();
    return keyId;
  }

  test.describe('Key Generation', () => {
    test('should access DNSSEC page for zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnssec|key|secure/i);
    });

    test('should display add key button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const addBtn = page.locator('a[href*="/dnssec/keys/add"], input[value*="Add"], button:has-text("Add")');
      await expect(addBtn.first()).toBeVisible();
    });

    test('should access add key page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should not display CSK info alert on modern PowerDNS', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      // The legacy-CSK guidance alert only renders for pre-4.0 PowerDNS
      // servers; the test environment always runs a modern server.
      const cskInfoAlert = page.locator('#csk-info-alert');
      await expect(cskInfoAlert).toHaveCount(0);
    });

    test('should display key type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const typeSelector = page.locator('select[name*="type"], input[name*="type"], input[type="radio"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should display algorithm selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const algoSelector = page.locator('select[name*="algo"], select[name*="algorithm"]');
      await expect(algoSelector.first()).toBeVisible();
    });

    test('should display key size selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const sizeSelector = page.locator('select[name*="size"], select[name*="bits"], input[name*="size"]');
      await expect(sizeSelector.first()).toBeVisible();
    });

    test('should generate KSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // addDnssecKey waits for the redirect and asserts the key count grew by one
      const keyId = await addDnssecKey(page, zoneId, { keyType: 'ksk' });
      expect(await listDnssecKeyIds(page, zoneId)).toContain(keyId);
    });

    test('should generate ZSK key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // addDnssecKey waits for the redirect and asserts the key count grew by one
      const keyId = await addDnssecKey(page, zoneId, { keyType: 'zsk' });
      expect(await listDnssecKeyIds(page, zoneId)).toContain(keyId);
    });
  });

  test.describe('Key Activation', () => {
    test('should display key activation status', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/active|inactive|status|key/i);
    });

    test('should activate inactive key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const keyId = await keyIdInState(page, false);
      // submitKeyToggle asserts the Activate/Deactivate link counts flipped
      expect(await submitKeyToggle(page, zoneId, keyId)).toBe('activated');
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should deactivate active key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const keyId = await keyIdInState(page, true);
      expect(await submitKeyToggle(page, zoneId, keyId)).toBe('deactivated');
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('DS Records', () => {
    test('should display DS records section', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display DNSKEY records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnskey|ds|key/i);
    });

    test('should show DS record formats', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Key Deletion', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await ensureDnssecKey(page, zoneId);
    });

    test('should access delete key confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('table a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*dnssec.*delete/);
    });

    test('should display delete confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('table a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).toContainText(/delete|confirm|remove/i);
    });

    test('should cancel key deletion', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLink = page.locator('table a[href*="/dnssec/keys/"][href*="/delete"]').first();
      await expect(deleteLink.first()).toBeVisible();
      await deleteLink.click();
      const cancelBtn = page.locator('main a:has-text("Cancel"), main button:has-text("Cancel")').first();
      await expect(cancelBtn).toBeVisible();
      await cancelBtn.click();
      await expect(page).toHaveURL(/.*\/dnssec$/);
    });

    test('should delete key without CSRF error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
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

  test.describe('DNSSEC Permissions', () => {
    test('admin should access DNSSEC settings', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/dnssec`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/you do not have|access denied|not authorized/i);
    });

    test('manager should access DNSSEC for own zones', async ({ page }) => {
      const managerZone = 'manager-zone.example.com';
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');

      const editLink = page.locator(`tr:has-text("${managerZone}") a[href*="/edit"]`).first();
      await expect(editLink).toBeVisible();

      const match = (await editLink.getAttribute('href')).match(/\/zones\/(\d+)\/edit/);
      expect(match, 'the edit link must carry a numeric zone id').not.toBeNull();

      await page.goto(`/zones/${match[1]}/dnssec`);

      await expect(page.locator('.card-header').first()).toContainText(managerZone);
      await expect(page.locator('[data-testid="system-message"].alert-danger')).toHaveCount(0);
    });

    test('viewer should have appropriate DNSSEC access', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/zones/"][href*="/edit"]').first();
      await expect(editLink.first()).toBeVisible();
      const href = await editLink.getAttribute('href');
      const zoneIdMatch = href?.match(/\/zones\/(\d+)\/edit/);
      expect(zoneIdMatch, 'the viewer zone list must carry a numeric zone id').not.toBeNull();

      await page.goto(`/zones/${zoneIdMatch[1]}/dnssec`);
      const bodyText = await page.locator('body').textContent() || '';
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(bodyText.toLowerCase()).toMatch(/dnssec|zone|key|denied|not authorized/i);
    });
  });
});
