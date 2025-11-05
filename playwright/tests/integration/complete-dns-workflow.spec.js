import { test, expect } from '@playwright/test';
import { login } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Complete DNS Management Workflow Integration', () => {
  const companyName = 'playwright-company';
  const primaryDomain = `${companyName}.com`;
  const subDomain = `sub.${primaryDomain}`;

  test.beforeAll(async ({ browser }) => {
    // Initial setup - login once for the entire suite
    const page = await browser.newPage();
    await page.goto('/login');
    await login(page, users.admin.username, users.admin.password);
    await page.close();
  });

  test('should complete full company DNS setup workflow', async ({ page }) => {
    await login(page, users.admin.username, users.admin.password);

    // Step 1: Create primary company domain
    await page.goto('/zones/add/master');

    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(primaryDomain);

    // Set company admin email
    const hasEmail = await page.locator('input[name*="email"], input[type="email"]').count() > 0;
    if (hasEmail) {
      await page.locator('input[name*="email"], input[type="email"]').first().fill(`admin@${primaryDomain}`);
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify domain creation
    await page.goto('/zones/forward');
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain(primaryDomain);

    // Step 2: Add essential DNS records for company infrastructure
    await page.locator(`tr:has-text("${primaryDomain}")`).locator('a').first().click();

    // Add website A record (www)
    const hasTypeSelect = await page.locator('select[name*="type"]').count() > 0;
    if (hasTypeSelect) {
      await page.locator('select[name*="type"]').selectOption('A');
      await page.locator('input[name*="name"]').clear();
      await page.locator('input[name*="name"]').fill('www');
      await page.locator('input[name*="content"], input[name*="value"]').clear();
      await page.locator('input[name*="content"], input[name*="value"]').fill('192.168.1.10');
      await page.locator('button[type="submit"]').click();

      // Add root domain A record
      await page.locator('select[name*="type"]').selectOption('A');
      await page.locator('input[name*="name"]').clear();
      await page.locator('input[name*="name"]').fill('@');
      await page.locator('input[name*="content"], input[name*="value"]').clear();
      await page.locator('input[name*="content"], input[name*="value"]').fill('192.168.1.10');
      await page.locator('button[type="submit"]').click();

      // Add mail server A record
      await page.locator('select[name*="type"]').selectOption('A');
      await page.locator('input[name*="name"]').clear();
      await page.locator('input[name*="name"]').fill('mail');
      await page.locator('input[name*="content"], input[name*="value"]').clear();
      await page.locator('input[name*="content"], input[name*="value"]').fill('192.168.1.20');
      await page.locator('button[type="submit"]').click();

      // Add MX record for email
      await page.locator('select[name*="type"]').selectOption('MX');
      await page.locator('input[name*="name"]').clear();
      await page.locator('input[name*="name"]').fill('@');
      await page.locator('input[name*="content"], input[name*="value"]').clear();
      await page.locator('input[name*="content"], input[name*="value"]').fill(`mail.${primaryDomain}.`);

      // Set MX priority
      const hasPriority = await page.locator('input[name*="prio"], input[name*="priority"]').count() > 0;
      if (hasPriority) {
        await page.locator('input[name*="prio"], input[name*="priority"]').clear();
        await page.locator('input[name*="prio"], input[name*="priority"]').fill('10');
      }

      await page.locator('button[type="submit"]').click();

      // Add CNAME for common services
      await page.locator('select[name*="type"]').selectOption('CNAME');
      await page.locator('input[name*="name"]').clear();
      await page.locator('input[name*="name"]').fill('ftp');
      await page.locator('input[name*="content"], input[name*="value"]').clear();
      await page.locator('input[name*="content"], input[name*="value"]').fill(`${primaryDomain}.`);
      await page.locator('button[type="submit"]').click();

      // Add TXT record for SPF
      await page.locator('select[name*="type"]').selectOption('TXT');
      await page.locator('input[name*="name"]').clear();
      await page.locator('input[name*="name"]').fill('@');
      const contentLocator = page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first();
      await contentLocator.clear();
      await contentLocator.fill('"v=spf1 mx a ip4:192.168.1.20 -all"');
      await page.locator('button[type="submit"]').click();

      // Add TXT record for DMARC
      await page.locator('select[name*="type"]').selectOption('TXT');
      await page.locator('input[name*="name"]').clear();
      await page.locator('input[name*="name"]').fill('_dmarc');
      const dmarcContentLocator = page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first();
      await dmarcContentLocator.clear();
      await dmarcContentLocator.fill(`"v=DMARC1; p=quarantine; rua=mailto:dmarc@${primaryDomain}"`);
      await page.locator('button[type="submit"]').click();
    }
  });

  test('should validate complete DNS infrastructure', async ({ page }) => {
    await login(page, users.admin.username, users.admin.password);

    // Final validation - check all components are working
    await page.goto('/zones/forward');

    // Verify primary domain exists
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain(primaryDomain);

    // Check individual domain records
    await page.locator(`tr:has-text("${primaryDomain}")`).locator('a').first().click();

    // Verify all essential records are present
    const recordsText = await page.locator('body').textContent();
    expect(recordsText).toContain('www'); // A record
    expect(recordsText).toContain('mail'); // Mail A record
    expect(recordsText).toContain('MX'); // MX record
    expect(recordsText).toContain('TXT'); // SPF/DMARC records
    expect(recordsText).toContain('CNAME'); // Service aliases
  });

  // Comprehensive cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await login(page, users.admin.username, users.admin.password);

    // Clean up all test domains
    await page.goto('/zones/forward');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes(companyName) || bodyText.includes(companyName.replace('-company', ''))) {
      const rows = page.locator(`tr:contains("${companyName}")`);
      const count = await rows.count();

      for (let i = 0; i < count; i++) {
        const row = rows.nth(i);
        const deleteLink = await row.locator('a, button').filter({ hasText: /Delete/i }).count();

        if (deleteLink > 0) {
          await row.locator('a, button').filter({ hasText: /Delete/i }).first().click();

          const confirmButton = await page.locator('button').filter({ hasText: /Yes|Confirm/i }).count();
          if (confirmButton > 0) {
            await page.locator('button').filter({ hasText: /Yes|Confirm/i }).click();
          }
        }
      }
    }

    await page.close();
  });
});
