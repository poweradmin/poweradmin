import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Complete Domain Management Workflow', () => {
  const testDomain = `test-domain-${Date.now()}.com`;
  const testEmail = 'admin@example.com';
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should complete full domain creation workflow', async ({ page }) => {
    // Step 1: Navigate to add master zone
    await page.goto('/zones/add/master');
    await expect(page).toHaveURL(/.*zones\/add\/master/);

    // Step 2: Fill in domain details
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);

    // Fill in admin email if field exists
    const hasEmail = await page.locator('input[name*="email"], input[type="email"]').count() > 0;
    if (hasEmail) {
      await page.locator('input[name*="email"], input[type="email"]').first().fill(testEmail);
    }

    // Fill in name servers if fields exist
    const hasNs = await page.locator('input[name*="ns"], input[name*="nameserver"]').count() > 0;
    if (hasNs) {
      await page.locator('input[name*="ns"], input[name*="nameserver"]').first().fill('ns1.example.com');
    }

    // Step 3: Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Step 4: Verify zone was created
    const bodyText = await page.locator('body').textContent();
    const hasSuccess = bodyText.includes('success') || bodyText.includes('added') || bodyText.includes('created');

    if (hasSuccess) {
      expect(bodyText).toMatch(/success|added|created/i);
    } else {
      // Check if we're redirected to zone list
      await expect(page).toHaveURL(/.*zones/);
    }

    // Step 5: Navigate to zones list and verify domain exists
    await page.goto('/zones/forward');
    const listText = await page.locator('body').textContent();
    expect(listText).toContain(testDomain);

    // Extract zone ID for later use
    const domainRow = page.locator(`tr:has-text("${testDomain}")`);
    const editHref = await domainRow.locator('a[href*="/zones/"]').first().getAttribute('href');

    if (editHref) {
      const match = editHref.match(/\/zones\/(\d+)/);
      if (match) {
        zoneId = match[1];
      }
    }
  });

  test('should add essential DNS records to the domain', async ({ page }) => {
    // Navigate to zones and find our test domain
    await page.goto('/zones/forward');

    // Click on the domain to edit records
    await page.locator(`tr:has-text("${testDomain}")`).locator('a').first().click();

    // Should be on zone edit page
    await expect(page).toHaveURL(/\/zones\/\d+\/edit/);

    // Add A record for www
    const hasForm = await page.locator('form').count() > 0;
    if (hasForm) {
      // Look for add record form or button
      const hasRecordInput = await page.locator('input[name*="name"], input[name*="record"]').count() > 0;

      if (hasRecordInput) {
        // Form is directly visible
        await page.locator('select[name*="type"]').selectOption('A');
        await page.locator('input[name*="name"]').fill('www');
        await page.locator('input[name*="content"], input[name*="value"]').fill('192.168.1.100');
        await page.locator('button[type="submit"]').click();
      } else {
        const hasAddButton = await page.locator('a, button').filter({ hasText: /Add|Create/i }).count();
        if (hasAddButton > 0) {
          // Need to click add record button first
          await page.locator('a, button').filter({ hasText: /Add|Create/i }).click();
          await page.locator('select[name*="type"]').selectOption('A');
          await page.locator('input[name*="name"]').fill('www');
          await page.locator('input[name*="content"], input[name*="value"]').fill('192.168.1.100');
          await page.locator('button[type="submit"]').click();
        }
      }
    }
  });

  test('should verify domain resolution and records', async ({ page }) => {
    await page.goto('/zones/forward');

    // Find and click on test domain
    await page.locator(`tr:has-text("${testDomain}")`).locator('a').first().click();

    // Verify we can see the records we added
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain('www');
    expect(bodyText).toContain('192.168.1.100');
  });

  test('should handle domain search functionality', async ({ page }) => {
    await page.goto('/search');

    // Search for our test domain
    await page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first().fill(testDomain);

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should find our domain
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain(testDomain);
  });

  // Cleanup: Delete the test domain
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/zones/forward');

    // Find and delete the test domain
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes(testDomain)) {
      const row = page.locator(`tr:has-text("${testDomain}")`);

      // Look for delete button/link
      const hasDelete = await row.locator('a, button').filter({ hasText: /Delete|Remove/i }).count();
      if (hasDelete > 0) {
        await row.locator('a, button').filter({ hasText: /Delete|Remove/i }).click();

        // Confirm deletion if needed
        const confirmExists = await page.locator('button').filter({ hasText: /Yes|Confirm/i }).count();
        if (confirmExists > 0) {
          await page.locator('button').filter({ hasText: /Yes|Confirm/i }).click();
        }
      }
    }

    await page.close();
  });
});
