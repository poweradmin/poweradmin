/**
 * Supermaster CRUD Operations Tests
 *
 * Tests for supermaster management including listing,
 * adding, and deleting supermasters.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Supermaster CRUD Operations', () => {
  test.describe('List Supermasters', () => {
    test('admin should access supermasters list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');

      await expect(page).toHaveURL(/.*supermasters/);
    });

    test('should display supermasters table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/supermaster|no.*supermaster|empty|ip|nameserver/i);
    });

    test('should display add supermaster button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');

      const addBtn = page.locator('a[href*="/supermasters/add"], input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('should display IP and nameserver columns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/ip|nameserver|master/i);
    });

    test('non-admin should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/supermasters');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !url.includes('supermasters');
      expect(accessDenied).toBeTruthy();
    });
  });

  test.describe('Add Supermaster', () => {
    test('should access add supermaster page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');
      await expect(page).toHaveURL(/.*supermasters\/add/);
    });

    test('should display IP field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');

      const ipField = page.locator('input[name*="ip"], input[name*="master"]').first();
      await expect(ipField).toBeVisible();
    });

    test('should display nameserver field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');

      const nsField = page.locator('input[name*="nameserver"], input[name*="ns"]').first();
      await expect(nsField).toBeVisible();
    });

    test('should create supermaster with valid IPv4', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueNs = `ns-${Date.now()}.example.com`;
      await page.goto('/supermasters/add');

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
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueNs = `ns-ipv6-${Date.now()}.example.com`;
      await page.goto('/supermasters/add');

      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('2001:db8::100');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');

      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill('ns.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/supermasters\/add/);
    });

    test('should reject empty nameserver', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');

      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.100.2');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      expect(url).toMatch(/supermasters\/add/);
    });

    test('should reject invalid IP format', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters/add');

      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('999.999.999.999');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill('ns.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('supermasters/add');
      expect(hasError).toBeTruthy();
    });

    test('should reject duplicate supermaster', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // First create a supermaster
      const uniqueNs = `ns-dup-${Date.now()}.example.com`;
      await page.goto('/supermasters/add');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.200.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Try to create same supermaster
      await page.goto('/supermasters/add');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.200.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const hasError = bodyText.toLowerCase().includes('exist') ||
                       bodyText.toLowerCase().includes('duplicate') ||
                       bodyText.toLowerCase().includes('error') ||
                       url.includes('supermasters/add');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Delete Supermaster', () => {
    test('should access delete confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // Create a supermaster to delete
      const uniqueNs = `ns-del-${Date.now()}.example.com`;
      await page.goto('/supermasters/add');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.250.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/supermasters');
      const row = page.locator(`tr:has-text("192.168.250.1")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="/delete"]').first();
        await deleteLink.click();
        await expect(page).toHaveURL(/.*delete/);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');
      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/supermasters');
      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/.*supermasters/);
        }
      }
    });

    test('should delete supermaster', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // Create a supermaster to delete
      const uniqueNs = `ns-todel-${Date.now()}.example.com`;
      await page.goto('/supermasters/add');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('192.168.251.1');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/supermasters');
      const row = page.locator(`tr:has-text("192.168.251.1")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="/delete"]').first();
        await deleteLink.click();

        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('IPv6 Supermaster Edit and Delete', () => {
    test('should edit supermaster with IPv6 address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueNs = `ns-ipv6-edit-${Date.now()}.example.com`;
      await page.goto('/supermasters/add');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('2001:db8::200');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/supermasters');
      const row = page.locator('tr:has-text("2001:db8::200")');

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="/edit"]').first();
        await editLink.click();

        const ipField = page.locator('input[name="master_ip"]').first();
        await expect(ipField).toHaveValue('2001:db8::200');

        const nsField = page.locator('input[name="ns_name"]').first();
        const updatedNs = `ns-ipv6-edited-${Date.now()}.example.com`;
        await nsField.fill(updatedNs);
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).not.toMatch(/not a valid ip|error/i);
      }
    });

    test('should delete supermaster with IPv6 address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueNs = `ns-ipv6-del-${Date.now()}.example.com`;
      await page.goto('/supermasters/add');
      await page.locator('input[name*="ip"], input[name*="master"]').first().fill('2001:db8::201');
      await page.locator('input[name*="nameserver"], input[name*="ns"]').first().fill(uniqueNs);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/supermasters');
      const row = page.locator('tr:has-text("2001:db8::201")');

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="/delete"]').first();
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).not.toMatch(/not a valid ip/i);

        const yesBtn = page.locator('a:has-text("Yes"), input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();

          const resultText = await page.locator('body').textContent();
          expect(resultText.toLowerCase()).not.toMatch(/not a valid ip|error/i);
        }
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/supermasters');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           url.includes('/login') ||
                           !url.includes('supermasters');
      expect(accessDenied).toBeTruthy();
    });

    test('client should not access supermasters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/supermasters');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           url.includes('/login') ||
                           !url.includes('supermasters');
      expect(accessDenied).toBeTruthy();
    });
  });
});
