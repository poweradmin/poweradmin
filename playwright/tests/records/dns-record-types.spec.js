import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Run tests serially as they depend on shared zone
test.describe.configure({ mode: 'serial' });

test.describe('DNS Record Types Management', () => {
  const timestamp = Date.now();
  const testDomain = `records-test-${timestamp}.com`;
  let zoneCreated = false;
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should create test zone for record testing', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(testDomain);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify zone was created and get zone ID
    await page.goto('/zones/forward?letter=all');
    const zoneRow = page.locator(`tr:has-text("${testDomain}")`);
    await expect(zoneRow).toBeVisible();
    const editLink = await zoneRow.locator('a[href*="/edit"]').first().getAttribute('href');
    const match = editLink.match(/\/zones\/(\d+)/);
    if (match) {
      zoneId = match[1];
    }
    zoneCreated = true;
  });

  test('should add A record successfully', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill('www');
    await page.locator('input[name*="content"]').first().fill('192.168.1.10');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add AAAA record successfully', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('AAAA');
    await page.locator('input[name*="name"]').first().fill('ipv6');
    await page.locator('input[name*="content"]').first().fill('2001:db8:85a3::8a2e:370:7334');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add MX record successfully', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

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

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add CNAME record successfully', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('CNAME');
    await page.locator('input[name*="name"]').first().fill('blog');
    await page.locator('input[name*="content"]').first().fill('www.example.com');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add TXT record successfully', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    await page.locator('select[name*="type"]').first().selectOption('TXT');
    await page.locator('input[name*="name"]').first().fill('_dmarc');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('v=DMARC1; p=none');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should show deprecated label for SPF record type in dropdown', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const spfOption = page.locator('select[name*="type"] option[value="SPF"]').first();
    if (await spfOption.count() > 0) {
      const optionText = await spfOption.textContent();
      expect(optionText).toContain('deprecated');
    }
  });

  test('should show deprecation warning when selecting SPF type', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const typeSelect = page.locator('select[name*="type"]').first();
    const spfOption = page.locator('select[name*="type"] option[value="SPF"]').first();
    if (await spfOption.count() > 0) {
      await typeSelect.selectOption('SPF');
      const warning = page.locator('.deprecated-type-warning').first();
      await expect(warning).toBeVisible();
      await expect(warning).toContainText('deprecated');
    }
  });

  test('should hide deprecation warning when switching to non-deprecated type', async ({ page }) => {
    test.skip(!zoneCreated || !zoneId, 'Zone not created');

    await page.goto(`/zones/${zoneId}/records/add`);
    await page.waitForLoadState('networkidle');

    const typeSelect = page.locator('select[name*="type"]').first();
    const spfOption = page.locator('select[name*="type"] option[value="SPF"]').first();
    if (await spfOption.count() > 0) {
      await typeSelect.selectOption('SPF');
      const warning = page.locator('.deprecated-type-warning').first();
      await expect(warning).toBeVisible();

      await typeSelect.selectOption('A');
      await expect(warning).not.toBeVisible();
    }
  });

  test('should cleanup test zone', async ({ page }) => {
    test.skip(!zoneCreated, 'Zone not created');

    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${testDomain}")`).locator('a[href*="/delete"]').first().click();

    const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
    await yesBtn.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
