import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensureTemplateExists } from '../../helpers/templates.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone Template Records', () => {
  const templateName = `templ-rec-${Date.now()}`;
  let templateId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    templateId = await ensureTemplateExists(page, templateName);
    await page.close();
  });

  test.describe('Add Template Records', () => {
    test('should access add template record page', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await expect(page).toHaveURL(/add_zone_templ_record/);
    });

    test('should display record type selector', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      const typeSelector = page.locator('select[name*="type"]');
      expect(await typeSelector.count()).toBeGreaterThan(0);
    });

    test('should add A record to template', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('www');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add AAAA record to template', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add MX record with priority', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail.[ZONE]');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('10');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add NS record with [ZONE] placeholder', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns1.[ZONE]');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record for SPF', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('@');
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('v=spf1 mx ~all');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add CNAME record', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('ftp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('www.[ZONE]');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add SRV record', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_sip._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10 5 5060 sip.[ZONE]');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty record content', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('empty');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/add_zone_templ_record/);
    });
  });

  test.describe('Edit Template Records', () => {
    test('should access edit template record page', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);
      const editLink = page.locator('a[href*="edit_zone_templ_record"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/edit_zone_templ_record/);
      }
    });

    test('should display current record values', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);
      const editLink = page.locator('a[href*="edit_zone_templ_record"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const contentField = page.locator('input[name*="content"], input[name*="value"]').first();
        if (await contentField.count() > 0) {
          const value = await contentField.inputValue();
          expect(value.length).toBeGreaterThan(0);
        }
      }
    });

    test('should update template record', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);
      const editLink = page.locator('a[href*="edit_zone_templ_record"]').first();
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
    test('should access delete template record confirmation', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);
      const deleteLink = page.locator('a[href*="delete_zone_templ_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_zone_templ_record/);
      }
    });

    test('should display delete confirmation message', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);
      const deleteLink = page.locator('a[href*="delete_zone_templ_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm/i);
      }
    });

    test('should cancel delete and return to template', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);
      const deleteLink = page.locator('a[href*="delete_zone_templ_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/edit_zone_templ/);
        }
      }
    });
  });

  test.describe('Template Record Permissions', () => {
    test('admin should manage template records', async ({ adminPage: page }) => {
      expect(templateId).toBeTruthy();
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await expect(page).toHaveURL(/add_zone_templ_record/);
    });

    test('manager should access template records for own templates', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator('table tbody tr').first();
      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_zone_templ"]').first();
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
    await page.goto('/index.php?page=list_zone_templ');
    const row = page.locator(`tr:has-text("${templateName}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a[href*="delete_zone_templ"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    }
    await page.close();
  });
});
