import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { expectRecordAdded } from './record-assert.js';
import { uniqueName, isApiModeInstance } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

const zone = useFileZone('reccrud');

test.describe('Record CRUD Operations', () => {
  test.describe('Add Record - A Record', () => {
    test('should add A record with valid IPv4', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('a');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('192.168.1.100');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label, content: '192.168.1.100' });
    });

    test('should reject A record with invalid IPv4', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill('invalid-ip');
      await page.locator('input[name*="content"]').first().fill('999.999.999.999');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page).toHaveURL(/\/records\/add/);
      await expect(page.locator('.alert-danger')).toContainText('Invalid IPv4 address format.');
    });
  });

  test.describe('Add Record - AAAA Record', () => {
    test('should add AAAA record with valid IPv6', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('aaaa');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label });
    });

    test('should add AAAA record with compressed IPv6', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('aaaa-short');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('2001:db8::1');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label });
    });

    test('should reject AAAA record with IPv4 address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('wrong-type');
      await page.locator('input[name*="content"]').first().fill('192.168.1.1');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page).toHaveURL(/\/records\/add/);
      await expect(page.locator('.alert-danger')).toContainText(/not a valid IPv6 address/i);
    });
  });

  test.describe('Add Record - MX Record', () => {
    test('should add MX record with priority', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('mx');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"]').first().fill(`mail-${label}.example.com`);

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { content: `mail-${label}.example.com`, search: `mail-${label}.example.com` });
    });

    test('should add MX record with high priority value', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('mx-high');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"]').first().fill(`backup-${label}.example.com`);

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('50');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { content: `backup-${label}.example.com`, search: `backup-${label}.example.com` });
    });
  });

  test.describe('Add Record - TXT Record', () => {
    test('should add TXT record with SPF', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('spf');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('@');
      await page.locator('input[name*="content"], textarea[name*="content"]').first().fill(`"v=spf1 include:${label}.example.com ~all"`);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { content: `include:${label}.example.com`, search: `include:${label}.example.com` });
    });

    test('should add TXT record with DMARC', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('dmarc');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`_dmarc.${label}`);
      await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: `_dmarc.${label}`, content: 'v=DMARC1' });
    });

    test('should add TXT record with special characters', async ({ page, baseURL }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('special');
      // The PowerDNS API rejects backslash-escaped quotes in TXT content (422), the SQL backend stores them
      const txtContent = isApiModeInstance(baseURL) ? '"test=value; key=123 & more"' : '"test=\\"value\\"; key=123"';

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"], textarea[name*="content"]').first().fill(txtContent);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label, content: 'key=123' });
    });
  });

  test.describe('Add Record - CNAME Record', () => {
    test('should add CNAME record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('cname');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('www.example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label, content: 'www.example.com' });
    });

    test('should add CNAME pointing to external domain', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('cname-ext');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('target.external.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label, content: 'target.external.com' });
    });
  });

  test.describe('Add Record - SRV Record', () => {
    test('should add SRV record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('srv');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill(`_sip._tcp.${label}`);
      await page.locator('input[name*="content"]').first().fill('5 5060 sip.example.com');

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: `_sip._tcp.${label}`, content: 'sip.example.com' });
    });
  });

  test.describe('Add Record - CAA Record', () => {
    test('should add CAA record', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('caa');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('CAA');
      await page.locator('input[name*="content"]').first().fill(`0 issue "${label}.letsencrypt.org"`);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { content: `${label}.letsencrypt.org`, search: `${label}.letsencrypt.org` });
    });
  });

  test.describe('Add Record - NS Record', () => {
    test('should add NS record for subdomain delegation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('ns');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('ns1.delegated.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label, content: 'ns1.delegated.com' });
    });
  });

  // The per-record edit and delete buttons are a user preference that is off by
  // default, but /records/{id}/edit and /delete work regardless. Each test adds
  // its own A record so it never edits or deletes seeded data.
  async function addARecord(page, zoneId) {
    const label = uniqueName('crud-rec');
    await page.goto(`/zones/${zoneId}/records/add`);
    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(label);
    await page.locator('input[name*="content"]').first().fill('192.0.2.77');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await expect(page.locator('.alert-danger')).toHaveCount(0);

    // Filter by the unique label so the row is found whatever page it lands on
    await page.goto(`/zones/${zoneId}/edit?search=${label}`);
    const hit = page.locator(`input[name^="record["][name$="][name]"][value*="${label}"]`);
    await expect(hit, `the added record ${label} must be listed on the zone edit page`).toHaveCount(1);
    const recordId = (await hit.getAttribute('name')).match(/record\[([^\]]+)\]/)[1];
    return { recordId, label };
  }

  test.describe('Edit Record', () => {
    test('should access edit record page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId } = await addARecord(page, zoneId);

      await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
      await expect(page).toHaveURL(/.*\/records\/.*\/edit/);
      await expect(page.locator('input[name="content"]')).toBeVisible();
    });

    test('should display record form with existing values', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId } = await addARecord(page, zoneId);

      await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);

      const contentField = page.locator('input[name="content"]');
      await expect(contentField).toBeVisible();
      await expect(contentField).toHaveValue('192.0.2.77');
    });

    test('should update record content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId, label } = await addARecord(page, zoneId);
      const newContent = `198.51.100.${1 + Math.floor(Math.random() * 250)}`;

      await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);
      await page.locator('input[name="content"]').fill(newContent);
      await page.locator('button[name="commit"]').click();

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      // Scoped to this test's own record, not the whole zone
      await page.goto(`/zones/${zoneId}/edit?search=${label}`);
      await expect(page.locator(`tr:has(input[name$="][name]"][value*="${label}"]) input[name$="][content]"]`)).toHaveValue(newContent);
    });

    test('should update record TTL', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId, label } = await addARecord(page, zoneId);
      const newTtl = String(7000 + Math.floor(Math.random() * 900));

      await page.goto(`/zones/${zoneId}/records/${recordId}/edit`);

      const ttlField = page.locator('input[name="ttl"]');
      await expect(ttlField).toBeVisible();
      await ttlField.fill(newTtl);
      await page.locator('button[name="commit"]').click();

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await page.goto(`/zones/${zoneId}/edit?search=${label}`);
      await expect(page.locator(`tr:has(input[name$="][name]"][value*="${label}"]) input[name$="][ttl]"]`)).toHaveValue(newTtl);
    });
  });

  test.describe('Delete Record', () => {
    test('should access delete record confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId } = await addARecord(page, zoneId);

      await page.goto(`/zones/${zoneId}/records/${recordId}/delete`);
      await expect(page).toHaveURL(/.*\/records\/.*\/delete/);
      await expect(page.locator('[data-testid="confirm-delete-record"]')).toBeVisible();
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId } = await addARecord(page, zoneId);

      await page.goto(`/zones/${zoneId}/records/${recordId}/delete`);

      await expect(page.locator('[data-testid="confirm-delete-record"]')).toContainText(/delete/i);
    });

    test('should cancel delete and return to zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId, label } = await addARecord(page, zoneId);

      await page.goto(`/zones/${zoneId}/records/${recordId}/delete`);

      // delete_actions() renders the cancel control as a link, not a button
      const cancelBtn = page.locator('main a:has-text("No, keep this record"), form a:has-text("No, keep this record")').first();
      await expect(cancelBtn).toBeVisible();
      await cancelBtn.click();
      await expect(page).toHaveURL(/\/zones\/\d+\/edit/);
      await page.goto(`/zones/${zoneId}/edit?search=${label}`);
      await expect(page.locator(`input[name^="record["][name$="][name]"][value*="${label}"]`)).toHaveCount(1);
    });

    test('should delete the record after confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const { recordId, label } = await addARecord(page, zoneId);

      await page.goto(`/zones/${zoneId}/records/${recordId}/delete`);
      await page.locator('[data-testid="confirm-delete-record"]').click();
      await expect(page).not.toHaveURL(/\/delete/);

      // addARecord already proved the label finds the row, so an empty result means it is gone
      await page.goto(`/zones/${zoneId}/edit?search=${label}`);
      await expect(page.locator(`input[name^="record["][name$="][name]"][value*="${label}"]`)).toHaveCount(0);
    });
  });

  test.describe('TTL Validation', () => {
    test('should accept valid TTL value', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;
      const label = uniqueName('ttl-test');

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('10.0.0.1');

      const ttlField = page.locator('input[name*="ttl"]').first();
      await expect(ttlField).toBeVisible();
      await ttlField.fill('86400');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expectRecordAdded(page, zoneId, { name: label, content: '10.0.0.1' });
    });

    test('should reject negative TTL value', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      const label = uniqueName('neg-ttl');
      await page.locator('input[name*="name"]').first().fill(label);
      await page.locator('input[name*="content"]').first().fill('10.0.0.2');

      const ttlField = page.locator('input[name*="ttl"]').first();
      await expect(ttlField).toBeVisible();
      await ttlField.fill('-1');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // A negative TTL must be rejected, so the record must not appear in the zone
      await expect(page).toHaveURL(/\/records\/add/);
      await page.goto(`/zones/${zoneId}/edit?search=${label}`);
      await expect(page.locator(`input[name^="record["][name$="][name]"][value*="${label}"]`)).toHaveCount(0);
    });
  });

  test.describe('Permission Tests', () => {
    test('admin should have full record access', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = zone.id;

      await page.goto(`/zones/${zoneId}/edit`);

      const addLink = page.locator('a[href*="/records/add"]');
      expect(await addLink.count()).toBeGreaterThan(0);
    });

    test('viewer should not see record modification links', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const editLink = page.locator('table a[href*="/edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();

      const deleteLinks = page.locator('a[href*="/records/"][href*="/delete"]');
      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(deleteLinks).toHaveCount(0);
    });
  });
});
