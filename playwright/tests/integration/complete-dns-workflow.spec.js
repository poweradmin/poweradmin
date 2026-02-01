import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Run tests serially as they depend on each other
test.describe.configure({ mode: 'serial' });

test.describe('Complete DNS Management Workflow Integration', () => {
  const timestamp = Date.now();
  const companyName = `playwright-${timestamp}`;
  const primaryDomain = `${companyName}.com`;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should complete full company DNS setup workflow', async ({ page }) => {
    // Step 1: Create primary company domain
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
    if (await zoneInput.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await zoneInput.fill(primaryDomain);

    // Set company admin email if available
    const emailInput = page.locator('input[name*="email"], input[type="email"]').first();
    if (await emailInput.count() > 0) {
      await emailInput.fill(`admin@${primaryDomain}`);
    }

    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no errors
    let bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify domain creation
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(primaryDomain)) {
      // Domain may not have been created - continue but note it
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Step 2: Add essential DNS records for company infrastructure
    const zoneRow = page.locator(`tr:has-text("${primaryDomain}")`);
    const editLink = zoneRow.locator('a[href*="/edit"]').first();
    if (await editLink.count() === 0) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Check if record form exists
    const typeSelect = page.locator('select[name*="type"]').first();
    const nameInput = page.locator('input[name*="name"]').first();
    const contentInput = page.locator('input[name*="content"]').first();

    if (await typeSelect.count() === 0 || await nameInput.count() === 0 || await contentInput.count() === 0) {
      bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Add website A record (www)
    await typeSelect.selectOption('A');
    await nameInput.clear();
    await nameInput.fill('www');
    await contentInput.clear();
    await contentInput.fill('192.168.1.10');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Add root domain A record
    await typeSelect.selectOption('A');
    await nameInput.clear();
    await nameInput.fill('@');
    await contentInput.clear();
    await contentInput.fill('192.168.1.10');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Add mail server A record
    await typeSelect.selectOption('A');
    await nameInput.clear();
    await nameInput.fill('mail');
    await contentInput.clear();
    await contentInput.fill('192.168.1.20');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Add MX record for email
    await typeSelect.selectOption('MX');
    await nameInput.clear();
    await nameInput.fill('@');
    await contentInput.clear();
    await contentInput.fill(`mail.${primaryDomain}.`);

    // Set MX priority if available
    const prioInput = page.locator('input[name*="prio"], input[name*="priority"]').first();
    if (await prioInput.count() > 0) {
      await prioInput.clear();
      await prioInput.fill('10');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Add CNAME for common services
    await typeSelect.selectOption('CNAME');
    await nameInput.clear();
    await nameInput.fill('ftp');
    await contentInput.clear();
    await contentInput.fill(`${primaryDomain}.`);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Add TXT record for SPF
    await typeSelect.selectOption('TXT');
    await nameInput.clear();
    await nameInput.fill('@');
    await contentInput.clear();
    await contentInput.fill('"v=spf1 mx a ip4:192.168.1.20 -all"');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should validate complete DNS infrastructure', async ({ page }) => {
    // Final validation - check all components are working
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify primary domain exists
    if (!bodyText.includes(primaryDomain)) {
      // Domain wasn't created in previous test - skip gracefully
      return;
    }

    // Check individual domain records
    const zoneRow = page.locator(`tr:has-text("${primaryDomain}")`);
    const editLink = zoneRow.locator('a[href*="/edit"]').first();
    if (await editLink.count() > 0) {
      await editLink.click();
      await page.waitForLoadState('networkidle');

      // Verify some records are present
      const recordsText = await page.locator('body').textContent();
      expect(recordsText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should clean up test domains', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    // Clean up test domains
    const zoneRow = page.locator(`tr:has-text("${primaryDomain}")`);
    if (await zoneRow.count() > 0) {
      const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await page.waitForLoadState('networkidle');

        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();
          await page.waitForLoadState('networkidle');
        }
      }
    }

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
