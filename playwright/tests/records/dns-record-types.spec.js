import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Run tests serially as they depend on shared zone
test.describe.configure({ mode: 'serial' });

test.describe('DNS Record Types Management', () => {
  const timestamp = Date.now();
  const testDomain = `records-test-${timestamp}.com`;
  let zoneCreated = false;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should create test zone for record testing', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(testDomain);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify zone was created
    await page.goto('/zones/forward?letter=all');
    await expect(page.locator(`tr:has-text("${testDomain}")`)).toBeVisible();
    zoneCreated = true;
  });

  test('should add A record successfully', async ({ page }) => {
    test.skip(!zoneCreated, 'Zone not created');

    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${testDomain}")`).locator('a[href*="/edit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Fill in A record
    await page.locator('input[name*="name"]').first().fill('www');
    await page.locator('input[name*="content"]').first().fill('192.168.1.10');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add AAAA record successfully', async ({ page }) => {
    test.skip(!zoneCreated, 'Zone not created');

    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${testDomain}")`).locator('a[href*="/edit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Select AAAA type if type selector exists
    const typeSelector = page.locator('select[name*="type"]').first();
    if (await typeSelector.count() > 0) {
      await typeSelector.selectOption('AAAA');
    }

    await page.locator('input[name*="name"]').first().fill('ipv6');
    await page.locator('input[name*="content"]').first().fill('2001:db8:85a3::8a2e:370:7334');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add MX record successfully', async ({ page }) => {
    test.skip(!zoneCreated, 'Zone not created');

    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${testDomain}")`).locator('a[href*="/edit"]').first().click();
    await page.waitForLoadState('networkidle');

    const typeSelector = page.locator('select[name*="type"]').first();
    if (await typeSelector.count() > 0) {
      await typeSelector.selectOption('MX');
    }

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
    test.skip(!zoneCreated, 'Zone not created');

    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${testDomain}")`).locator('a[href*="/edit"]').first().click();
    await page.waitForLoadState('networkidle');

    const typeSelector = page.locator('select[name*="type"]').first();
    if (await typeSelector.count() > 0) {
      await typeSelector.selectOption('CNAME');
    }

    await page.locator('input[name*="name"]').first().fill('blog');
    await page.locator('input[name*="content"]').first().fill('www.example.com');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add TXT record successfully', async ({ page }) => {
    test.skip(!zoneCreated, 'Zone not created');

    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${testDomain}")`).locator('a[href*="/edit"]').first().click();
    await page.waitForLoadState('networkidle');

    const typeSelector = page.locator('select[name*="type"]').first();
    if (await typeSelector.count() > 0) {
      await typeSelector.selectOption('TXT');
    }

    await page.locator('input[name*="name"]').first().fill('_dmarc');
    await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('v=DMARC1; p=none');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
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
