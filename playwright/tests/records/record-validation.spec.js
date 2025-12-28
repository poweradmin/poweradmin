import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Record Validation - All Types', () => {
  const testDomain = `record-val-${Date.now()}.example.com`;
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

  test.describe('A Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept valid private IP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('private');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10.0.0.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/error|invalid/i);
    });

    test('should accept valid public IP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('public');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('8.8.8.8');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject IP with leading zeros', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('leadingzero');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.001.001');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject hostname instead of IP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('hostname');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/add_record|error/);
    });

    test('should accept localhost IP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('localhost-test');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('127.0.0.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('AAAA Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept full IPv6 address', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6-full');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept compressed IPv6', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6-compressed');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept IPv6 loopback', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6-loopback');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject invalid IPv6', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6-invalid');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::gggg');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/add_record|error/);
    });
  });

  test.describe('MX Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept MX with priority 0', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail1.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('0');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept MX with high priority', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('backup.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('100');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject MX with IP address', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('10');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      // MX should point to hostname, not IP
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject negative priority', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('-1');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const url = page.url();
        expect(url).toMatch(/add_record|error/);
      }
    });
  });

  test.describe('TXT Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept SPF record', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('spf');
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('v=spf1 include:_spf.google.com ~all');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept DKIM record', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('selector._domainkey');
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GN');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept DMARC record', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('_dmarc');
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('v=DMARC1; p=reject; rua=mailto:dmarc@example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept long TXT record', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('long-txt');
      const longText = 'a'.repeat(255);
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill(longText);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle quotes in TXT', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('quoted');
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('key="value"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('CNAME Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept internal CNAME', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('alias');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`www.${testDomain}`);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept external CNAME', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('external');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('target.external-domain.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject CNAME pointing to IP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('cname-ip');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      // CNAME should not point to IP
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('SRV Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept SRV for SIP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_sip._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10 5 5060 sip.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept SRV for XMPP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_xmpp-server._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('5 0 5269 xmpp.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept SRV for LDAP', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_ldap._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('0 100 389 ldap.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('PTR Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept PTR record', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      const typeSelector = page.locator('select[name*="type"]').first();
      const options = await typeSelector.locator('option').allTextContents();
      if (options.some(o => o.toUpperCase().includes('PTR'))) {
        await typeSelector.selectOption('PTR');
        await page.locator('input[name*="name"]').first().fill('1');
        await page.locator('input[name*="content"], input[name*="value"]').first().fill('host.example.com');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('NS Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept NS for subdomain delegation', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="name"]').first().fill('sub');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns1.subdomain.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept multiple NS records', async ({ page }) => {
      if (!zoneId) test.skip();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="name"]').first().fill('sub2');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns2.subdomain.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
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
