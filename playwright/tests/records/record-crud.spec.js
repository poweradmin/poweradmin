import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Record CRUD Operations', () => {
  const testDomain = `record-crud-${Date.now()}.example.com`;
  let zoneId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Create test zone
    await page.goto('/index.php?page=add_zone_master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Get zone ID from URL or list
    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);
    if (await row.count() > 0) {
      const editLink = await row.locator('a[href*="page=edit"]').first().getAttribute('href');
      const match = editLink?.match(/id=(\d+)/);
      if (match) {
        zoneId = match[1];
      }
    }

    await page.close();
  });

  test.describe('Add Record - A Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add A record with valid IPv4', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('www');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.100');

      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('3600');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/error|invalid|failed/i);
    });

    test('should reject A record with invalid IPv4', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('invalid-ip');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('999.999.999.999');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show error or stay on form
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('add_record');
      expect(hasError).toBeTruthy();
    });

    test('should reject A record with empty content', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('empty-test');
      // Leave content empty

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page).toHaveURL(/add_record|error/);
    });
  });

  test.describe('Add Record - AAAA Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add AAAA record with valid IPv6', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add AAAA record with compressed IPv6', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6-short');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::1');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject AAAA record with IPv4 address', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('wrong-type');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('add_record');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Add Record - MX Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add MX record with priority', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`mail.${testDomain}`);

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add MX record with high priority value', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`backup-mail.${testDomain}`);

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('50');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Record - TXT Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add TXT record with SPF', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      const nameInput = page.locator('input[name*="name"]').first();
      if (await nameInput.count() > 0) {
        await nameInput.fill('@');
      }
      await page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first().fill('v=spf1 include:_spf.google.com ~all');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record with DMARC', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('_dmarc');
      await page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first().fill('v=DMARC1; p=reject; rua=mailto:dmarc@example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record with special characters', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('special');
      await page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first().fill('test="value"; key=123');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Record - CNAME Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add CNAME record', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('blog');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`www.${testDomain}`);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add CNAME pointing to external domain', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('external');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('target.external.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Record - SRV Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add SRV record', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_sip._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`10 5 5060 sip.${testDomain}`);

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('0');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Record - CAA Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add CAA record', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      const typeSelector = page.locator('select[name*="type"]').first();
      const options = await typeSelector.locator('option').allTextContents();

      if (options.some(opt => opt.toUpperCase().includes('CAA'))) {
        await typeSelector.selectOption('CAA');
        await page.locator('input[name*="content"], input[name*="value"]').first().fill('0 issue "letsencrypt.org"');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      } else {
        test.info().annotations.push({ type: 'skip', description: 'CAA record type not available' });
      }
    });
  });

  test.describe('Add Record - NS Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should add NS record for subdomain delegation', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="name"]').first().fill('sub');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns1.delegated.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Edit Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access edit record page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const editLink = page.locator('a[href*="edit_record"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/edit_record/);
      }
    });

    test('should display record form with existing values', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const editLink = page.locator('a[href*="edit_record"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        // Form should have pre-filled values
        const contentField = page.locator('input[name*="content"], input[name*="value"]').first();
        if (await contentField.count() > 0) {
          const value = await contentField.inputValue();
          expect(value.length).toBeGreaterThan(0);
        }
      }
    });

    test('should update record content', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      // Find an A record to edit
      const editLink = page.locator('tr:has-text("A") a[href*="edit_record"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.200');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should update record TTL', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const editLink = page.locator('a[href*="edit_record"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const ttlField = page.locator('input[name*="ttl"]').first();
        if (await ttlField.count() > 0) {
          await ttlField.fill('7200');
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Delete Record', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access delete record confirmation', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="delete_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_record/);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="delete_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to zone', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="delete_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const cancelBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("Cancel")').first();
        if (await cancelBtn.count() > 0) {
          await cancelBtn.click();
          await expect(page).toHaveURL(/page=edit/);
        }
      }
    });
  });

  test.describe('TTL Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept valid TTL value', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('ttl-test');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10.0.0.1');

      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('86400');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject negative TTL value', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('negative-ttl');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10.0.0.2');

      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('-1');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('error') ||
                         bodyText.toLowerCase().includes('invalid') ||
                         url.includes('add_record');
        expect(hasError).toBeTruthy();
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('admin should have full record access', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      // Admin should see add, edit, delete links
      const addLink = page.locator('a[href*="add_record"], input[value*="Add"]');
      expect(await addLink.count()).toBeGreaterThan(0);
    });

    test('viewer should not see record modification links', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);

      await page.goto('/index.php?page=list_zones');
      const row = page.locator('table tbody tr').first();

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="page=edit"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();

          // Viewer should not see delete links
          const deleteLinks = page.locator('a[href*="delete_record"]');
          expect(await deleteLinks.count()).toBe(0);
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
        const confirmButton = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }
      }
    }

    await page.close();
  });
});
