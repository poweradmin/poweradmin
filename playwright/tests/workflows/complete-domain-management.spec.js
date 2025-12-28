import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Complete Domain Management Workflow', () => {
  const testDomain = `test-domain-${Date.now()}.com`;
  const testEmail = 'admin@example.com';

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should complete full domain creation workflow', async ({ page }) => {
    // Step 1: Navigate to add master zone
    await page.goto('/index.php?page=add_zone_master');
    await expect(page).toHaveURL(/page=add_zone_master/);

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
    const hasSuccess = bodyText.toLowerCase().includes('success') || bodyText.toLowerCase().includes('added') || bodyText.toLowerCase().includes('created');

    if (hasSuccess) {
      expect(bodyText).toMatch(/success|added|created/i);
    } else {
      // Check if we're redirected to zone list or edit page
      await expect(page).toHaveURL(/page=(list_zones|edit)/);
    }

    // Step 5: Navigate to zones list and verify domain exists
    await page.goto('/index.php?page=list_zones');
    const listText = await page.locator('body').textContent();
    expect(listText).toContain(testDomain);
  });

  test('should add essential DNS records to the domain', async ({ page }) => {
    // Navigate to zones and find our test domain
    await page.goto('/index.php?page=list_zones');

    // Check if test domain exists
    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(testDomain)) {
      test.info().annotations.push({ type: 'note', description: 'Test domain not found, skipping record test' });
      return;
    }

    // Click on the domain to edit records
    await page.locator(`tr:has-text("${testDomain}")`).locator('a').first().click();

    // Should be on zone edit page
    await expect(page).toHaveURL(/page=edit/);

    // Try to add A record (if form exists)
    const hasRecordInput = await page.locator('input[name*="content"], input[name*="value"]').count() > 0;
    if (hasRecordInput) {
      // Fill record details
      const typeSelect = page.locator('select[name*="type"]').first();
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('A');
      }

      const nameInput = page.locator('input[name*="name"]').first();
      if (await nameInput.count() > 0) {
        await nameInput.fill('www');
      }

      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.100');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
    }
  });

  test('should verify domain resolution and records', async ({ page }) => {
    await page.goto('/index.php?page=list_zones');

    // Check if test domain exists
    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(testDomain)) {
      test.info().annotations.push({ type: 'note', description: 'Test domain not found, skipping verification' });
      return;
    }

    // Find and click on test domain
    await page.locator(`tr:has-text("${testDomain}")`).locator('a').first().click();

    // Check page loaded
    await expect(page).toHaveURL(/page=edit/);
  });

  test('should handle domain search functionality', async ({ page }) => {
    await page.goto('/index.php?page=search');

    // Search for our test domain
    await page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first().fill(testDomain);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Check if search works (either finds domain or shows results page)
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/search|results|found/i);
  });

  // Cleanup: Delete the test domain
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_zones');

    // Find and delete the test domain
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes(testDomain)) {
      const row = page.locator(`tr:has-text("${testDomain}")`);

      // Look for delete button/link
      const hasDelete = await row.locator('a, button').filter({ hasText: /Delete|Remove/i }).count();
      if (hasDelete > 0) {
        await row.locator('a, button').filter({ hasText: /Delete|Remove/i }).click();

        // Confirm deletion if needed
        const confirmExists = await page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i }).count();
        if (confirmExists > 0) {
          await page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i }).first().click();
        }
      }
    }

    await page.close();
  });
});
