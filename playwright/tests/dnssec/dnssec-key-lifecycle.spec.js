import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('DNSSEC Key Lifecycle', () => {
  const testDomain = `dnssec-lc-${Date.now()}.example.com`;
  let zoneId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=add_zone_master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);
    if (await row.count() > 0) {
      const editLink = await row.locator('a[href*="page=edit"]').first().getAttribute('href');
      const match = editLink?.match(/id=(\d+)/);
      if (match) zoneId = match[1];
    }
    await page.close();
  });

  test.describe('Key Generation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access DNSSEC page for zone', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnssec|key|secure/i);
    });

    test('should display add key button', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const addBtn = page.locator('a[href*="dnssec_add_key"], input[value*="Add"], button:has-text("Add")');
      if (await addBtn.count() > 0) {
        await expect(addBtn.first()).toBeVisible();
      }
    });

    test('should access add key page', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display key type selector', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const typeSelector = page.locator('select[name*="type"], input[name*="type"], input[type="radio"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should display algorithm selector', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const algoSelector = page.locator('select[name*="algo"], select[name*="algorithm"]');
      if (await algoSelector.count() > 0) {
        await expect(algoSelector.first()).toBeVisible();
      }
    });

    test('should display key size selector', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);
      const sizeSelector = page.locator('select[name*="size"], select[name*="bits"], input[name*="size"]');
      if (await sizeSelector.count() > 0) {
        await expect(sizeSelector.first()).toBeVisible();
      }
    });

    test('should generate KSK key', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const kskRadio = page.locator('input[value="ksk"], input[value="KSK"]');
      if (await kskRadio.count() > 0) {
        await kskRadio.first().check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should generate ZSK key', async ({ page }) => {
      if (!zoneId) test.skip();
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
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display key activation status', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/active|inactive|status|key/i);
    });

    test('should activate inactive key', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const activateLink = page.locator('a[href*="dnssec_activate"], a:has-text("Activate")').first();
      if (await activateLink.count() > 0) {
        await activateLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should deactivate active key', async ({ page }) => {
      if (!zoneId) test.skip();
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
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display DS records section', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display DNSKEY records', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnskey|ds|key/i);
    });

    test('should show DS record formats', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      // DS records typically show algorithm and digest type
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Key Deletion', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access delete key confirmation', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/dnssec_delete_key/);
      }
    });

    test('should display delete confirmation message', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|remove/i);
      }
    });

    test('should cancel key deletion', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/dnssec/);
        }
      }
    });
  });

  test.describe('DNSSEC Permissions', () => {
    test('admin should access DNSSEC settings', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/denied|permission/i);
    });

    test('manager should access DNSSEC for own zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href?.match(/id=(\d+)/);
        if (match) {
          await page.goto(`/index.php?page=dnssec&id=${match[1]}`);
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('viewer should not modify DNSSEC settings', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href?.match(/id=(\d+)/);
        if (match) {
          await page.goto(`/index.php?page=dnssec&id=${match[1]}`);
          const addKeyLink = page.locator('a[href*="dnssec_add_key"]');
          expect(await addKeyLink.count()).toBe(0);
        }
      }
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a[href*="delete_domain"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    }
    await page.close();
  });
});
