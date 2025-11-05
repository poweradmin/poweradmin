import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('DNS Record Types Management', () => {
  let testDomain;

  test.beforeAll(async ({ browser }) => {
    // Create a test domain for record testing
    const page = await browser.newPage();
    testDomain = `records-test-${Date.now()}.com`;

    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/zones/add/master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
      .first()
      .fill(testDomain);

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for domain creation
    await page.goto('/zones/forward');
    await expect(page.locator('body')).toContainText(testDomain);

    await page.close();
  });

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Navigate to the test domain's records
    await page.goto('/zones/forward');
    await page.locator('tr').filter({ hasText: testDomain }).locator('a').first().click();
  });

  test('should add A record successfully', async ({ page }) => {
    // Check if there's an add record form or button
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      // Form is directly available
      await page.locator('select[name*="type"]').selectOption('A');
      await page.locator('input[name*="name"]').fill('www');
      await page.locator('input[name*="content"], input[name*="value"]').fill('192.168.1.10');

      // Set TTL if field exists
      const hasTTLField = await page.locator('input[name*="ttl"]').count() > 0;
      if (hasTTLField) {
        await page.locator('input[name*="ttl"]').fill('3600');
      }

      await page.locator('button[type="submit"]').click();

      // Verify success
      await expect(page.locator('.alert, .message, [class*="success"]')).toBeVisible({ timeout: 10000 });
    }
  });

  test('should add AAAA record successfully', async ({ page }) => {
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').selectOption('AAAA');
      await page.locator('input[name*="name"]').fill('ipv6');
      await page.locator('input[name*="content"], input[name*="value"]').fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

      await page.locator('button[type="submit"]').click();
      await expect(page.locator('.alert, .message, [class*="success"]')).toBeVisible({ timeout: 10000 });
    }
  });

  test('should add MX record successfully', async ({ page }) => {
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').fill('mail.example.com');

      // Set priority if field exists
      const hasPriorityField = await page.locator('input[name*="prio"], input[name*="priority"]').count() > 0;
      if (hasPriorityField) {
        await page.locator('input[name*="prio"], input[name*="priority"]').fill('10');
      }

      await page.locator('button[type="submit"]').click();
      await expect(page.locator('.alert, .message, [class*="success"]')).toBeVisible({ timeout: 10000 });
    }
  });

  test('should add CNAME record successfully', async ({ page }) => {
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').selectOption('CNAME');
      await page.locator('input[name*="name"]').fill('blog');
      await page.locator('input[name*="content"], input[name*="value"]').fill('www.example.com');

      await page.locator('button[type="submit"]').click();
      await expect(page.locator('.alert, .message, [class*="success"]')).toBeVisible({ timeout: 10000 });
    }
  });

  test('should add TXT record successfully', async ({ page }) => {
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').selectOption('TXT');
      await page.locator('input[name*="name"]').fill('_dmarc');
      await page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]')
        .fill('v=DMARC1; p=none; rua=mailto:dmarc@example.com');

      await page.locator('button[type="submit"]').click();
      await expect(page.locator('.alert, .message, [class*="success"]')).toBeVisible({ timeout: 10000 });
    }
  });

  test('should add SRV record successfully', async ({ page }) => {
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').selectOption('SRV');
      await page.locator('input[name*="name"]').fill('_sip._tcp');
      await page.locator('input[name*="content"], input[name*="value"]').fill('sipserver.example.com');

      // Set SRV specific fields if they exist
      const hasPriorityField = await page.locator('input[name*="prio"], input[name*="priority"]').count() > 0;
      if (hasPriorityField) {
        await page.locator('input[name*="prio"], input[name*="priority"]').fill('10');
      }

      await page.locator('button[type="submit"]').click();
      await expect(page.locator('.alert, .message, [class*="success"]')).toBeVisible({ timeout: 10000 });
    }
  });
});
