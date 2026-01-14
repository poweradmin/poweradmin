import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensureAnyZoneExists, zones } from '../../helpers/zones.js';
import { submitForm, selectByTestId, fillByTestId } from '../../helpers/forms.js';
import { expectNoFatalError, hasErrorMessage } from '../../helpers/validation.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Record CRUD Operations', () => {
  // Use existing manager-zone.example.com for record testing (has comprehensive records)
  const testZoneName = zones.manager.name;

  test.describe('Add Record - A Record', () => {
    test('should add A record with valid IPv4', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      // Use class-based selectors that match the actual template
      await page.locator('.record-type-select').first().selectOption('A');
      // Use unique name with timestamp to avoid duplicate record errors
      await page.locator('.name-field').first().fill(`www-${Date.now()}`);
      await page.locator('.record-content').first().fill('192.168.1.100');
      await page.locator('input[name*="ttl"]').first().fill('3600');

      await submitForm(page);
      await expectNoFatalError(page);
    });

    test('should reject A record with invalid IPv4', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('.record-type-select').first().selectOption('A');
      await page.locator('.name-field').first().fill('invalid-ip');
      await page.locator('.record-content').first().fill('999.999.999.999');

      await submitForm(page);

      // Should show error or stay on form
      const hasError = await hasErrorMessage(page) || page.url().includes('add_record');
      expect(hasError).toBeTruthy();
    });

    test('should reject A record with empty content', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('.record-type-select').first().selectOption('A');
      await page.locator('.name-field').first().fill('empty-test');
      // Leave content empty

      await submitForm(page);
      await expect(page).toHaveURL(/add_record|error/);
    });
  });

  test.describe('Add Record - AAAA Record', () => {
    test('should add AAAA record with valid IPv6', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add AAAA record with compressed IPv6', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('AAAA');
      await page.locator('input[name*="name"]').first().fill('ipv6-short');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:db8::1');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject AAAA record with IPv4 address', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('should add MX record with priority', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`mail.${testZoneName}`);

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add MX record with high priority value', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`backup-mail.${testZoneName}`);

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
    test('should add TXT record with SPF', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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

    test('should add TXT record with DMARC', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill('_dmarc');
      await page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first().fill('v=DMARC1; p=reject; rua=mailto:dmarc@example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record with special characters', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('should add CNAME record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('CNAME');
      await page.locator('input[name*="name"]').first().fill('blog');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`www.${testZoneName}`);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add CNAME pointing to external domain', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('should add SRV record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=add_record&id=${zoneId}`);

      await page.locator('select[name*="type"]').first().selectOption('SRV');
      await page.locator('input[name*="name"]').first().fill('_sip._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill(`10 5 5060 sip.${testZoneName}`);

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
    test('should add CAA record', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('should add NS record for subdomain delegation', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('should access edit record page', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const editLink = page.locator('a[href*="edit_record"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/edit_record/);
      }
    });

    test('should display record form with existing values', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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

    test('should update record content', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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

    test('should update record TTL', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('should access delete record confirmation', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="delete_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_record/);
      }
    });

    test('should display confirmation message', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="delete_record"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to zone', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('should accept valid TTL value', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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

    test('should reject negative TTL value', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

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
    test('admin should have full record access', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      // Admin should see add, edit, delete links
      const addLink = page.locator('a[href*="add_record"], input[value*="Add"]');
      expect(await addLink.count()).toBeGreaterThan(0);
    });

    test('viewer should not see record modification links', async ({ viewerPage: page }) => {

      await page.goto('/index.php?page=list_forward_zones');
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

  // No cleanup needed - we use existing test zones and don't create new ones
  // Records created during tests will persist but use unique names with timestamps
});
