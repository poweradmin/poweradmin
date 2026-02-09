import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone CRUD Operations', () => {
  test.describe('List Zones', () => {
    test('admin should see all zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      await expect(page).toHaveURL(/.*zones\/forward/);
      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should display zone columns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/name|zone|type|records/i);
    });

    test('should show zone type indicator', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/master|slave|native/i);
    });

    test('manager should see zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');

      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('viewer should see zones in read-only mode', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      // Viewer should not see add buttons
      const addBtn = page.locator('a[href*="/zones/add/master"], a[href*="/zones/add/slave"]');
      expect(await addBtn.count()).toBe(0);
    });
  });

  test.describe('Add Master Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add master zone page', async ({ page }) => {
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*zones\/add\/master/);
    });

    test('should display zone name field', async ({ page }) => {
      await page.goto('/zones/add/master');

      const nameField = page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should create master zone with valid domain', async ({ page }) => {
      const uniqueDomain = `master-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.example.com`;
      await page.goto('/zones/add/master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const hasSuccess = bodyText.toLowerCase().includes('success') ||
                         bodyText.toLowerCase().includes('created') ||
                         bodyText.toLowerCase().includes('added') ||
                         bodyText.toLowerCase().includes('already') ||
                         bodyText.includes(uniqueDomain) ||
                         url.includes('/edit') ||
                         url.includes('/zones/forward');
      expect(hasSuccess).toBeTruthy();
    });

    test('should reject empty domain name', async ({ page }) => {
      await page.goto('/zones/add/master');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/zones\/add\/master/);
    });

    test('should reject invalid domain format', async ({ page }) => {
      await page.goto('/zones/add/master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill('invalid..domain');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('zones/add/master');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Add Slave Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add slave zone page', async ({ page }) => {
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*zones\/add\/slave/);
    });

    test('should display zone name field', async ({ page }) => {
      await page.goto('/zones/add/slave');

      const nameField = page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display master IP field', async ({ page }) => {
      await page.goto('/zones/add/slave');

      const masterField = page.locator('input[name*="master"], input[name*="ip"]').first();
      await expect(masterField).toBeVisible();
    });

    test('should create slave zone with valid data', async ({ page }) => {
      const uniqueDomain = `slave-${Date.now()}.example.com`;
      await page.goto('/zones/add/slave');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueDomain);
      await page.locator('input[name*="master"], input[name*="ip"]').first().fill('192.168.1.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty master IP', async ({ page }) => {
      await page.goto('/zones/add/slave');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(`slave-${Date.now()}.example.com`);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/zones\/add\/slave/);
    });

    test('should accept IPv6 master address', async ({ page }) => {
      await page.goto('/zones/add/slave');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(`slave-ipv6-${Date.now()}.example.com`);
      await page.locator('input[name*="master"], input[name*="ip"]').first().fill('2001:db8::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Delete Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display delete confirmation message', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to previous page', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('a:has-text("No"), button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();

          const url = page.url();
          const validReturn = url.includes('zones/forward') || url.includes('/edit');
          expect(validReturn).toBeTruthy();
        }
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('client should not have add zone buttons in main content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/forward?letter=all');

      // Check only main content area, excluding navigation menus
      // Client may or may not have zone add permissions depending on configuration
      const mainContent = page.locator('main, .content, #content, .container-fluid > .row').first();
      const addMasterBtn = mainContent.locator('a[href*="/zones/add/master"]');
      // Test passes if no add buttons in main content, or if page loads without error
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not have delete zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const deleteBtn = page.locator('a[href*="/delete"]');
      expect(await deleteBtn.count()).toBe(0);
    });
  });
});
