/**
 * Record Validation Tests - All Types
 *
 * Tests for DNS record validation including A, AAAA, MX,
 * TXT, CNAME, and other record types.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

// Helper to get a zone ID for testing
async function getTestZoneId(page) {
  await page.goto('/zones/forward?letter=all');
  const editLink = page.locator('table a[href*="/zones/"][href*="/edit"]').first();
  if (await editLink.count() > 0) {
    const href = await editLink.getAttribute('href');
    const match = href.match(/\/zones\/(\d+)\/edit/);
    return match ? match[1] : null;
  }
  return null;
}

test.describe('Record Validation - All Types', () => {
  test.describe('A Record Validation', () => {
    test('should accept valid private IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`private-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10.0.0.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/error|invalid/i);
    });

    test('should accept valid public IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`public-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('8.8.8.8');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject hostname instead of IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`hostname-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/records\/add|error/);
    });

    test('should accept localhost IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`localhost-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('127.0.0.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('AAAA Record Validation', () => {
    test('should accept full IPv6 address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-full-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept compressed IPv6', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-comp-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept IPv6 loopback', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-loop-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('::1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject invalid IPv6', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(`ipv6-inv-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::gggg');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/records\/add|error/);
    });
  });

  test.describe('MX Record Validation', () => {
    test('should accept MX with priority 0', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail1.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('0');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept MX with high priority', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('backup.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) await prioField.fill('100');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject negative priority', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail.example.com');
      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('-1');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        const url = page.url();
        expect(url).toMatch(/records\/add|error/);
      }
    });
  });

  test.describe('TXT Record Validation', () => {
    test('should accept SPF record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`spf-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('"v=spf1 include:_spf.google.com ~all"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept DKIM record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`sel${Date.now()}._domainkey`);
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('"v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GN"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept DMARC record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`_dmarc${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept long TXT record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`long-txt-${Date.now()}`);
      const longText = '"' + 'a'.repeat(253) + '"';
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill(longText);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show error for unquoted TXT content on add page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      const recordName = `unquoted-${Date.now()}`;
      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(recordName);
      await page.locator('input[name*="content"], input[name*="value"], textarea').first().fill('v=spf1 include:example.com ~all');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should stay on add record page with visible error
      await expect(page).toHaveURL(/\/records\/add/);
      await expect(page.locator('.alert-danger')).toBeVisible();
      const alertText = await page.locator('.alert-danger').textContent();
      expect(alertText).toMatch(/quotes|content/i);

      // Form data should be restored
      const contentValue = await page.locator('input[name*="content"], input[name*="value"], textarea').first().inputValue();
      expect(contentValue).toContain('v=spf1');
      const typeValue = await page.locator('select[name*="type"]').first().inputValue();
      expect(typeValue).toBe('TXT');
    });

    test('should restore all rows on multi-row batch failure', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      // Fill first row with unquoted TXT
      await page.locator('select[name="records[0][type]"]').selectOption('TXT');
      await page.locator('input[name="records[0][name]"]').fill('row1');
      await page.locator('input[name="records[0][content]"]').fill('unquoted content');

      // Add a second row
      await page.locator('button', { hasText: 'Add another record' }).click();
      await page.locator('select[name="records[1][type]"]').selectOption('TXT');
      await page.locator('input[name="records[1][name]"]').fill('row2');
      await page.locator('input[name="records[1][content]"]').fill('also unquoted');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should stay on add page with error
      await expect(page).toHaveURL(/\/records\/add/);
      await expect(page.locator('.alert-danger')).toBeVisible();

      // Both rows should be restored
      const rows = page.locator('#recordsTableBody .record-row');
      await expect(rows).toHaveCount(2);

      const row1Content = await page.locator('input[name="records[0][content]"]').inputValue();
      expect(row1Content).toContain('unquoted content');
      const row2Content = await page.locator('input[name="records[1][content]"]').inputValue();
      expect(row2Content).toContain('also unquoted');
    });

    test('should preserve PTR checkbox after validation error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);

      // A record type - PTR checkbox should become visible
      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await page.locator('input[name="records[0][name]"]').fill(`ptr-test-${Date.now()}`);
      await page.locator('input[name="records[0][content]"]').fill('not-an-ip');

      // Check the PTR checkbox (need to wait for JS visibility toggle)
      const ptrCheckbox = page.locator('input[name="records[0][reverse]"]');
      await ptrCheckbox.waitFor({ state: 'attached' });
      await ptrCheckbox.evaluate(el => { el.style.visibility = 'visible'; });
      await ptrCheckbox.check();

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should stay on add page with error
      await expect(page).toHaveURL(/\/records\/add/);
      await expect(page.locator('.alert-danger')).toBeVisible();

      // PTR checkbox should still be checked
      const restoredCheckbox = page.locator('input[name="records[0][reverse]"]');
      await expect(restoredCheckbox).toBeChecked();
    });
  });

  test.describe('CNAME Record Validation', () => {
    test('should accept internal CNAME', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(`alias-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('www.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should accept external CNAME', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(`ext-alias-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('cdn.cloudflare.net');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject IP address for CNAME', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(`cname-ip-${Date.now()}`);
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = url.includes('records/add') || bodyText.toLowerCase().includes('error');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('NS Record Validation', () => {
    test('should accept valid NS record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns1.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('SRV Record Validation', () => {
    test('should accept valid SRV record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_sip._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('10 5 5060 sipserver.example.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('CAA Record Validation', () => {
    test('should accept valid CAA record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('CAA');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('0 issue "letsencrypt.org"');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
