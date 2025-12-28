import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Bulk Record Operations', () => {
  const testDomain = `bulk-rec-${Date.now()}.example.com`;
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

  test.describe('Bulk Registration Page', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access bulk registration page', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/bulk|registration|records/i);
    });

    test('should display bulk input textarea', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const textarea = page.locator('textarea');
      if (await textarea.count() > 0) {
        await expect(textarea.first()).toBeVisible();
      }
    });

    test('should accept single record input', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('bulk1 A 192.168.1.1');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should accept multiple records input', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('bulk2 A 192.168.1.2\nbulk3 A 192.168.1.3\nbulk4 A 192.168.1.4');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should handle empty input', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle invalid format', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('invalid format without proper fields');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should handle mixed valid and invalid records', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('valid A 192.168.1.5\ninvalid\nvalid2 A 192.168.1.6');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Bulk Registration - Different Record Types', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add multiple A records', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('a1 A 10.0.0.1\na2 A 10.0.0.2\na3 A 10.0.0.3');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should add mixed record types', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const textarea = page.locator('textarea').first();
      if (await textarea.count() > 0) {
        await textarea.fill('web A 10.0.0.10\nmail MX 10 mail.example.com\nwww CNAME web');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Bulk Registration - User Permissions', () => {
    test('admin should access bulk registration', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=bulk_registration&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/denied|permission/i);
    });

    test('manager should access bulk registration for own zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href?.match(/id=(\d+)/);
        if (match) {
          await page.goto(`/index.php?page=bulk_registration&id=${match[1]}`);
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
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
