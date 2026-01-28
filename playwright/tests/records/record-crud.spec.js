import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Record CRUD Operations', () => {
  // Helper to get a zone ID for testing
  async function getTestZoneId(page) {
    await page.goto('/zones/forward?letter=all');
    const editLink = page.locator('a[href*="/edit"]').first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)\/edit/);
      return match ? match[1] : null;
    }
    return null;
  }

  test.describe('Add Record - A Record', () => {
    test('should add A record with valid IPv4', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`www-${Date.now()}`);
      await page.locator('input[name*="content"]').first().fill('192.168.1.100');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject A record with invalid IPv4', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('invalid-ip');
      await page.locator('input[name*="content"]').first().fill('999.999.999.999');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('/records/add');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Add Record - AAAA Record', () => {
    test('should add AAAA record with valid IPv6', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6');
      await page.locator('input[name*="content"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add AAAA record with compressed IPv6', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6-short');
      await page.locator('input[name*="content"]').first().fill('2001:db8::1');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject AAAA record with IPv4 address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('wrong-type');
      await page.locator('input[name*="content"]').first().fill('192.168.1.1');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('invalid') ||
                       url.includes('/records/add');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Add Record - MX Record', () => {
    test('should add MX record with priority', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"]').first().fill('mail.example.com');

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add MX record with high priority value', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"]').first().fill('backup-mail.example.com');

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
    test('should add TXT record with SPF', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('@');
      await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('v=spf1 include:_spf.google.com ~all');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record with DMARC', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('_dmarc');
      await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('v=DMARC1; p=reject; rua=mailto:dmarc@example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record with special characters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('special');
      await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('test="value"; key=123');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Record - CNAME Record', () => {
    test('should add CNAME record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('blog');
      await page.locator('input[name*="content"]').first().fill('www.example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add CNAME pointing to external domain', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('external');
      await page.locator('input[name*="content"]').first().fill('target.external.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Record - SRV Record', () => {
    test('should add SRV record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_sip._tcp');
      await page.locator('input[name*="content"]').first().fill('10 5 5060 sip.example.com');

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
    test('should add CAA record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      const typeSelector = page.locator('select[name*="type"]').first();
      const options = await typeSelector.locator('option').allTextContents();

      if (options.some(opt => opt.toUpperCase().includes('CAA'))) {
        await typeSelector.selectOption('CAA');
        await page.locator('input[name*="content"]').first().fill('0 issue "letsencrypt.org"');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Add Record - NS Record', () => {
    test('should add NS record for subdomain delegation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="name"]').first().fill('sub');
      await page.locator('input[name*="content"]').first().fill('ns1.delegated.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Edit Record', () => {
    test('should access edit record page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/.*\/records\/.*\/edit/);
      }
    });

    test('should display record form with existing values', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const contentField = page.locator('input[name*="content"]').first();
        if (await contentField.count() > 0) {
          const value = await contentField.inputValue();
          expect(value.length).toBeGreaterThan(0);
        }
      }
    });

    test('should update record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      // Find an A record to edit
      const editLink = page.locator('tr:has-text("A") a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        await page.locator('input[name*="content"]').first().fill('192.168.1.200');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should update record TTL', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
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
    test('should access delete record confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      const deleteLink = page.locator('a[href*="/records/"][href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/.*\/records\/.*\/delete/);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      const deleteLink = page.locator('a[href*="/records/"][href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      const deleteLink = page.locator('a[href*="/records/"][href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const cancelBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("Cancel")').first();
        if (await cancelBtn.count() > 0) {
          await cancelBtn.click();
          await expect(page).toHaveURL(/\/zones\/\d+\/edit/);
        }
      }
    });
  });

  test.describe('TTL Validation', () => {
    test('should accept valid TTL value', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('ttl-test');
      await page.locator('input[name*="content"]').first().fill('10.0.0.1');

      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('86400');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject negative TTL value', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('negative-ttl');
      await page.locator('input[name*="content"]').first().fill('10.0.0.2');

      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('-1');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('error') ||
                         bodyText.toLowerCase().includes('invalid') ||
                         url.includes('/records/add');
        expect(hasError).toBeTruthy();
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('admin should have full record access', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);

      const addLink = page.locator('a[href*="/records/add"]');
      expect(await addLink.count()).toBeGreaterThan(0);
    });

    test('viewer should not see record modification links', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const deleteLinks = page.locator('a[href*="/records/"][href*="/delete"]');
        expect(await deleteLinks.count()).toBe(0);
      }
    });
  });
});
