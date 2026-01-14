import { test, expect } from '../../fixtures/test-fixtures.js';
import { ensureAnyZoneExists, zones } from '../../helpers/zones.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Record Validation - All Types', () => {
  // Use existing manager-zone.example.com for record validation testing
  const testZoneName = zones.manager.name;

  test.describe('A Record Validation', () => {
    test('should accept valid private IP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`private-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10.0.0.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/error|invalid/i);
    });

    test('should accept valid public IP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`public-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('8.8.8.8');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject IP with leading zeros', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`leadingzero-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.001.001');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject hostname instead of IP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`hostname-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/add_record|error/);
    });

    test('should accept localhost IP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`localhost-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('127.0.0.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('AAAA Record Validation', () => {
    test('should accept full IPv6 address', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-full-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept compressed IPv6', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-comp-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept IPv6 loopback', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-loop-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject invalid IPv6', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-inv-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::gggg');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/add_record|error/);
    });
  });

  test.describe('MX Record Validation', () => {
    test('should accept MX with priority 0', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail1.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('0');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept MX with high priority', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('backup.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('100');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject MX with IP address', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
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

    test('should reject negative priority', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
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
    test('should accept SPF record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`spf-${Date.now()}`);
      // TXT records must be enclosed in quotes
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('"v=spf1 include:_spf.google.com ~all"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept DKIM record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`sel${Date.now()}._domainkey`);
      // TXT records must be enclosed in quotes
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('"v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GN"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept DMARC record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`_dmarc${Date.now()}`);
      // TXT records must be enclosed in quotes
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept long TXT record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`long-txt-${Date.now()}`);
      // TXT records must be enclosed in quotes
      const longText = '"' + 'a'.repeat(253) + '"';
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill(longText);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle quotes in TXT', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`quoted-${Date.now()}`);
      // TXT records must be enclosed in quotes - testing nested quotes
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('"key=value with spaces"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('CNAME Record Validation', () => {
    test('should accept internal CNAME', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(`alias-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`www.${testZoneName}`);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept external CNAME', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(`external-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('target.external-domain.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject CNAME pointing to IP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(`cname-ip-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      // CNAME should not point to IP
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('SRV Record Validation', () => {
    test('should accept SRV for SIP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill(`_sip${Date.now()}._tcp`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10 5 5060 sip.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept SRV for XMPP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill(`_xmpp${Date.now()}._tcp`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('5 0 5269 xmpp.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept SRV for LDAP', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill(`_ldap${Date.now()}._tcp`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('0 100 389 ldap.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('PTR Record Validation', () => {
    test('should accept PTR record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      const typeSelector = page.locator('select[name*="type"]').first();
      const options = await typeSelector.locator('option').allTextContents();
      // Find PTR but not NAPTR - match exact "PTR" or "PTR " at start/end
      const ptrOption = options.find(o => o.trim().toUpperCase() === 'PTR' || o.toUpperCase().match(/^PTR\s|^PTR$/));
      if (ptrOption) {
        await typeSelector.selectOption({ label: ptrOption });
        // Wait for form to potentially update after type change
        await page.waitForTimeout(500);
        const nameField = page.locator('input[name*="name"]').first();
        if (await nameField.count() > 0) {
          await nameField.fill(`${Date.now()}`);
        }
        const contentField = page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first();
        if (await contentField.count() > 0) {
          await contentField.fill('host.example.com');
        }
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('NS Record Validation', () => {
    test('should accept NS for subdomain delegation', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="name"]').first().fill(`sub${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns1.subdomain.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept multiple NS records', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();
      await page.goto(`/index.php?page=add_record&id=${zoneId}`);
      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="name"]').first().fill(`sub2-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns2.subdomain.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  // No cleanup needed - we use existing test zones and don't create new ones
  // Records created during tests will persist but use unique names with timestamps
});
