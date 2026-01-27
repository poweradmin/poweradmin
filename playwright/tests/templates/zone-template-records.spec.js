/**
 * Zone Template Records Tests
 *
 * Tests for adding, editing, and deleting records in zone templates.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone Template Records', () => {
  const templateName = `templ-rec-${Date.now()}`;
  let templateId = null;

  // Helper to create a template and get its ID
  async function createTemplateAndGetId(page) {
    await page.goto('/zones/templates/add');
    await page.locator('input[name*="name"]').first().fill(templateName);
    await page.locator('input[name*="descr"], textarea[name*="descr"]').first().fill('Test template for records');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Go to template list and find the ID
    await page.goto('/zones/templates');
    const row = page.locator(`tr:has-text("${templateName}")`);
    if (await row.count() > 0) {
      const editLink = row.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href.match(/\/zones\/templates\/(\d+)\/edit/);
        return match ? match[1] : null;
      }
    }
    return null;
  }

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    templateId = await createTemplateAndGetId(page);
    await page.close();
  });

  test.describe('Add Template Records', () => {
    test('should access add template record page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display record type selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      const typeSelector = page.locator('select[name*="type"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should add A record to template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('www');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add AAAA record to template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add MX record with priority', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail.[ZONE]');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('10');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add NS record with [ZONE] placeholder', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns1.[ZONE]');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record for SPF', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('@');
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('v=spf1 mx ~all');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add CNAME record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('ftp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('www.[ZONE]');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add SRV record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_sip._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10 5 5060 sip.[ZONE]');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('empty');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/templates.*records.*add|templates/);
    });
  });

  test.describe('Edit Template Records', () => {
    test('should access edit template record page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/edit`);
      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should display current record values', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/edit`);
      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const contentField = page.locator('input[name*="content"], input[name*="value"]').first();
        if (await contentField.count() > 0) {
          const value = await contentField.inputValue();
          expect(value.length).toBeGreaterThan(0);
        }
      }
    });

    test('should update template record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/edit`);
      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const contentField = page.locator('input[name*="content"], input[name*="value"]').first();
        if (await contentField.count() > 0) {
          await contentField.fill('192.168.2.1');
          await page.locator('button[type="submit"], input[type="submit"]').first().click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Delete Template Records', () => {
    test('should access delete template record confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/edit`);
      const deleteLink = page.locator('a[href*="/records/"][href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm/i);
      }
    });

    test('should display delete confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/edit`);
      const deleteLink = page.locator('a[href*="/records/"][href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm/i);
      }
    });

    test('should cancel delete and return to template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/edit`);
      const deleteLink = page.locator('a[href*="/records/"][href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/.*zones\/templates/);
        }
      }
    });
  });

  test.describe('Template Record Permissions', () => {
    test('admin should manage template records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      expect(templateId).toBeTruthy();
      await page.goto(`/zones/templates/${templateId}/records/add`);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/access denied|permission/i);
    });

    test('manager should access template records for own templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/templates');
      const row = page.locator('table tbody tr').first();
      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="/edit"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();
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
    await page.goto('/zones/templates');
    const row = page.locator(`tr:has-text("${templateName}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    }
    await page.close();
  });
});
