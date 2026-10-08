import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Run tests serially; they share the worker's throwaway zone
test.describe.configure({ mode: 'serial' });

test.describe('DNS Record Types Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should add A record successfully', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill('www');
    await page.locator('input[name*="content"]').first().fill('192.168.1.10');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should add AAAA record successfully', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('AAAA');
    await page.locator('input[name*="name"]').first().fill('ipv6');
    await page.locator('input[name*="content"]').first().fill('2001:db8:85a3::8a2e:370:7334');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should add MX record successfully', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('MX');
    await page.locator('input[name*="content"]').first().fill('mail.example.com');

    // Set priority if available
    const prioField = page.locator('input[name*="prio"]');
    if (await prioField.count() > 0) {
      await prioField.fill('10');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should add CNAME record successfully', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('CNAME');
    await page.locator('input[name*="name"]').first().fill('blog');
    await page.locator('input[name*="content"]').first().fill('www.example.com');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should add TXT record successfully', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('TXT');
    await page.locator('input[name*="name"]').first().fill('_dmarc');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('v=DMARC1; p=none');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should show deprecated label for SPF record type in dropdown', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const spfOption = page.locator('select[name*="type"] option[value="SPF"]').first();
    if (await spfOption.count() > 0) {
      const optionText = await spfOption.textContent();
      expect(optionText).toContain('deprecated');
    }

    await page.locator('input[name*="name"]').first().fill('_dmarc');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('v=DMARC1; p=none');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should show deprecation warning when selecting SPF type', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    // SPF is in the default domain_record_types, so the option is always offered here
    const typeSelect = page.locator('select[name*="type"]').first();
    await expect(page.locator('select[name*="type"] option[value="SPF"]').first()).toHaveCount(1);

    await typeSelect.selectOption('SPF');
    const warning = page.locator('.deprecated-type-warning').first();
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('deprecated');
  });

  test('should hide deprecation warning when switching to non-deprecated type', async ({ page, workerZone }) => {
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const typeSelect = page.locator('select[name*="type"]').first();
    await expect(page.locator('select[name*="type"] option[value="SPF"]').first()).toHaveCount(1);

    await typeSelect.selectOption('SPF');
    const warning = page.locator('.deprecated-type-warning').first();
    await expect(warning).toBeVisible();

    await typeSelect.selectOption('A');
    await expect(warning).not.toBeVisible();
  });
});
