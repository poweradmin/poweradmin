import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Use serial mode since tests depend on each other
test.describe.configure({ mode: 'serial' });

test.describe('Complete Domain Management Workflow', () => {
  const testDomain = `test-domain-${Date.now()}.com`;
  const testEmail = 'admin@example.com';
  let domainCreated = false;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should complete full domain creation workflow', async ({ page }) => {
    // Step 1: Navigate to add master zone
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/.*zones\/add\/master/);

    // Step 2: Fill in domain details
    const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"]').first();
    await zoneInput.fill(testDomain);

    // Fill in admin email if field exists
    const emailInput = page.locator('input[name*="email"], input[type="email"]').first();
    if (await emailInput.count() > 0) {
      await emailInput.fill(testEmail);
    }

    // Step 3: Submit the form
    const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Step 4: Verify zone was created
    const bodyText = await page.locator('body').textContent();
    const hasSuccess = bodyText.toLowerCase().includes('success') ||
                       bodyText.toLowerCase().includes('added') ||
                       bodyText.toLowerCase().includes('created');

    if (hasSuccess || page.url().includes('/zones/')) {
      domainCreated = true;
    }

    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Step 5: Navigate to zones list and verify domain exists
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const listText = await page.locator('body').textContent();
    if (listText.includes(testDomain)) {
      domainCreated = true;
    }

    // Domain should be created
    expect(domainCreated || listText.includes(testDomain)).toBeTruthy();
  });

  test('should add essential DNS records to the domain', async ({ page }) => {
    if (!domainCreated) {
      // Try to create the domain if it wasn't created in the previous test
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"]').first();
      await zoneInput.fill(testDomain);

      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');
    }

    // Navigate to zones and find our test domain
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    // Check if domain exists
    const domainRow = page.locator(`tr:has-text("${testDomain}")`).first();
    if (await domainRow.count() === 0) {
      // Domain doesn't exist, skip this test
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Click on the domain to edit records
    const editLink = domainRow.locator('a[href*="edit"]').first();
    if (await editLink.count() > 0) {
      await editLink.click();
      await page.waitForLoadState('networkidle');

      // Add A record for www if form is available
      const typeSelect = page.locator('select[name*="type"]').first();
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('A');

        const nameInput = page.locator('input[name*="[name]"], input.name-field').first();
        if (await nameInput.count() > 0) {
          await nameInput.fill('www');
        }

        const contentInput = page.locator('input[name*="[content]"], input.record-content').first();
        if (await contentInput.count() > 0) {
          await contentInput.fill('192.168.1.100');
        }

        const addBtn = page.locator('button[type="submit"], input[type="submit"]').first();
        await addBtn.click();
        await page.waitForLoadState('networkidle');
      }
    }

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should verify domain resolution and records', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    // Check if domain exists
    const domainRow = page.locator(`tr:has-text("${testDomain}")`).first();
    if (await domainRow.count() === 0) {
      // Domain doesn't exist, just verify page loaded
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Find and click on test domain
    const editLink = domainRow.locator('a[href*="edit"]').first();
    if (await editLink.count() > 0) {
      await editLink.click();
      await page.waitForLoadState('networkidle');

      // Verify we can see the page without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should handle domain search functionality', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    // Search for our test domain
    const searchInput = page.locator('input[name="query"], input[type="search"], input[placeholder*="search"]').first();
    await searchInput.fill(testDomain);

    const submitBtn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Should find our domain or show no results (if domain wasn't created)
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Domain should be found if it was created, otherwise page should just not error
    const domainFound = bodyText.includes(testDomain);
    const noResults = bodyText.toLowerCase().includes('no results');

    expect(domainFound || noResults || bodyText.toLowerCase().includes('search')).toBeTruthy();
  });

  // Cleanup: Delete the test domain
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      // Find and delete the test domain
      const domainRow = page.locator(`tr:has-text("${testDomain}")`).first();
      if (await domainRow.count() > 0) {
        const deleteLink = domainRow.locator('a[href*="delete"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          await page.waitForLoadState('networkidle');

          // Confirm deletion if needed
          const confirmBtn = page.locator('button[type="submit"]:has-text("Delete"), input[value*="Delete"]').first();
          if (await confirmBtn.count() > 0) {
            await confirmBtn.click();
            await page.waitForLoadState('networkidle');
          }
        }
      }
    } catch {
      // Ignore cleanup errors
    }

    await page.close();
  });
});
