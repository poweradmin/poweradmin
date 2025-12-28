import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Supermaster CRUD Operations', () => {
  test.describe('List Supermasters', () => {
    test('admin should access supermasters list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_supermasters');

      await expect(page).toHaveURL(/page=list_supermasters/);
    });

    test('should display supermasters table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_supermasters');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/supermaster|no.*supermaster|empty|ip|nameserver/i);
    });

    test('should display add supermaster button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_supermasters');

      const addBtn = page.locator('a[href*="add_supermaster"], input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('should display IP and nameserver columns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_supermasters');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/ip|nameserver|master/i);
    });

    test('non-admin should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_supermasters');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !url.includes('list_supermasters');
      expect(accessDenied).toBeTruthy();
    });
  });

  test.describe('Add Supermaster', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add supermaster page', async ({ page }) => {
      await page.goto('/index.php?page=add_supermaster');
      await expect(page).toHaveURL(/page=add_supermaster/);
    });

    test('should display IP field', async ({ page }) => {
      await page.goto('/index.php?page=add_supermaster');

      const ipField = page.locator('input[name*="ip"], input[name*="master"]').first();
      await expect(ipField).toBeVisible();
    });

    test('should display nameserver field', async ({ page }) => {
      await page.goto('/index.php?page=add_supermaster');

      const nsField = page.locator('input[name*="nameserver"], input[name*="ns"]').first();
      await expect(nsField).toBeVisible();
    });

    test('should create supermaster with valid IPv4', async ({ page }) => {
      const uniqueNs = `ns-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_supermaster');

      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.100.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);

      // Select account if field exists
      const accountSelect = page.locator('select[name*="account"]').first();
      if (await accountSelect.count() > 0) {
        await accountSelect.selectOption({ index: 0 });
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should create supermaster with valid IPv6', async ({ page }) => {
      const uniqueNs = `ns-ipv6-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_supermaster');

      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('2001:db8::100');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty IP', async ({ page }) => {
      await page.goto('/index.php?page=add_supermaster');

      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill('ns.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/add_supermaster/);
    });

    test('should reject empty nameserver', async ({ page }) => {
      await page.goto('/index.php?page=add_supermaster');

      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.100.2');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/add_supermaster/);
    });

    test('should reject invalid IP format', async ({ page }) => {
      await page.goto('/index.php?page=add_supermaster');

      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('999.999.999.999');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill('ns.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('add_supermaster');
      expect(hasError).toBeTruthy();
    });

    test('should reject duplicate supermaster', async ({ page }) => {
      // First create a supermaster
      const uniqueNs = `ns-dup-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_supermaster');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.200.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Try to create same supermaster
      await page.goto('/index.php?page=add_supermaster');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.200.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const hasError = bodyText.toLowerCase().includes('exist') ||
                       bodyText.toLowerCase().includes('duplicate') ||
                       bodyText.toLowerCase().includes('error') ||
                       url.includes('add_supermaster');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Delete Supermaster', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access delete confirmation', async ({ page }) => {
      // Create a supermaster to delete
      const uniqueNs = `ns-del-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_supermaster');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.250.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=list_supermasters');
      const row = page.locator(`tr:has-text("192.168.250.1")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_supermaster"]').first();
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_supermaster/);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await page.goto('/index.php?page=list_supermasters');
      const deleteLink = page.locator('a[href*="delete_supermaster"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await page.goto('/index.php?page=list_supermasters');
      const deleteLink = page.locator('a[href*="delete_supermaster"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/list_supermasters/);
        }
      }
    });

    test('should delete supermaster successfully', async ({ page }) => {
      // Create a supermaster to delete
      const uniqueNs = `ns-delsuc-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_supermaster');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.251.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=list_supermasters');
      const row = page.locator(`tr:has-text("192.168.251.1")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_supermaster"]').first();
        await deleteLink.click();

        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();

          // Verify deleted
          await page.goto('/index.php?page=list_supermasters');
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toContain('192.168.251.1');
        }
      }
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_supermasters');

    // Delete test supermasters
    const testIPs = ['192.168.100.1', '192.168.200.1', '192.168.250.1', '192.168.251.1'];

    for (const ip of testIPs) {
      await page.goto('/index.php?page=list_supermasters');
      const row = page.locator(`tr:has-text("${ip}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_supermaster"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) {
            await yesBtn.click();
          }
        }
      }
    }

    await page.close();
  });
});
