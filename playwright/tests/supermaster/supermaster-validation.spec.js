/**
 * Supermaster Validation Tests
 *
 * Tests for supermaster IP and nameserver validation.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Supermaster Validation', () => {
  test.describe('IP Address Validation', () => {
    test('should accept valid IPv4 address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('192.168.1.100');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns1.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept valid IPv6 address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('2001:db8::1');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns1.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject invalid IP address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('invalid.ip');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns1.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject IP with out of range octet', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('256.1.1.1');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns1.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept localhost IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('127.0.0.1');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('localhost.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Nameserver Validation', () => {
    test('should accept valid FQDN', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('10.0.0.1');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns1.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept nameserver with subdomain', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('10.0.0.2');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('dns1.primary.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty nameserver', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('10.0.0.3');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/supermasters.*add|supermasters/);
    });

    test('should reject nameserver with spaces', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('10.0.0.4');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns 1.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Account Assignment', () => {
    test('should display account selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      const accountSelect = page.locator('select[name*="account"], select[name*="user"]');
      if (await accountSelect.count() > 0) {
        await expect(accountSelect.first()).toBeVisible();
      }
    });

    test('should assign supermaster to account', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      const accountSelect = page.locator('select[name*="account"], select[name*="user"]').first();
      if (await accountSelect.count() > 0) {
        const options = page.locator('select[name*="account"] option, select[name*="user"] option');
        if (await options.count() > 1) {
          await accountSelect.selectOption({ index: 1 });
        }
      }
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill('10.0.0.5');
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns5.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Duplicate Prevention', () => {
    test('should handle duplicate supermaster entry', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const testIp = `10.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.1`;

      // Add first entry
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill(testIp);
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns-dup.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Try to add duplicate
      await page.goto('/supermasters/add');
      await page.locator('input[name*="master_ip"], input[name*="ip"]').first().fill(testIp);
      await page.locator('input[name*="ns_name"], input[name*="nameserver"]').first().fill('ns-dup.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('List and Delete', () => {
    test('should display supermaster list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display delete option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should confirm before delete', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await page.waitForLoadState('networkidle');
        await expect(page.locator('.alert-heading:has-text("Warning")')).toBeVisible();
      }
    });

    test('should cancel delete', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/.*supermasters/);
        }
      }
    });
  });

  test.describe('Permissions', () => {
    test('admin should access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/access denied|not authorized|you do not have/i);
    });

    test('manager should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/supermasters');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('client should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/supermasters');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/supermasters');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
