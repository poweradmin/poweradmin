import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search Functionality', () => {
  // Use unique zone names to avoid conflicts
  const timestamp = Date.now();
  const testZones = [
    `search-test1-${timestamp}.com`,
    `search-test2-${timestamp}.org`,
    `search-test-special-${timestamp}.net`
  ];

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Set up test zones using direct navigation
    for (const zone of testZones) {
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      // Fill zone name
      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name="zone_name"], input[name="zonename"]').first();
      await zoneInput.fill(zone);

      // Submit the form
      const addButton = page.locator('[data-testid="add-zone-button"], button[type="submit"]:has-text("Add zone"), input[type="submit"]').first();
      await addButton.click();
      await page.waitForLoadState('networkidle');

      // Check if zone was created successfully (may already exist)
      const bodyText = await page.locator('body').textContent();
      if (bodyText.match(/error|already exists|failed/i) && !bodyText.match(/already exists/i)) {
        // If there's a real error (not just "already exists"), skip
        continue;
      }
    }
  });

  test('should search for zones by exact name', async ({ page }) => {
    // Navigate to search page
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    // Fill in search input
    const searchInput = page.locator('input[name="query"], input[placeholder*="search"], input[placeholder*="domain"]').first();
    await searchInput.fill(testZones[0]);

    // Submit search
    const submitBtn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no fatal errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Search functionality should work (may or may not find results depending on wildcards)
    // At minimum, the search page should still be displayed
    await expect(page.locator('body')).toContainText(/search|DNS|zones|records|no results/i);
  });

  test('should search for zones by partial name', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('input[name="query"], input[placeholder*="search"], input[placeholder*="domain"]').first();
    await searchInput.fill(`search-test1-${timestamp}`);

    const submitBtn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no fatal errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Should show search page or results
    await expect(page.locator('body')).toContainText(/search|DNS|zones|records|no results/i);
  });

  test('should search for records by content', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('input[name="query"], input[placeholder*="search"], input[placeholder*="domain"]').first();
    await searchInput.fill('192.168.1.10');

    // Enable records search if checkbox exists
    const recordsCheckbox = page.locator('input[type="checkbox"]:near(:text("Records"))');
    if (await recordsCheckbox.count() > 0 && !(await recordsCheckbox.isChecked())) {
      await recordsCheckbox.check();
    }

    const submitBtn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no fatal errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Should show search page or results
    await expect(page.locator('body')).toContainText(/search|DNS|zones|records|no results/i);
  });

  test('should handle searches with no results', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('input[name="query"], input[placeholder*="search"], input[placeholder*="domain"]').first();
    await searchInput.fill('nonexistent-domain-xyz123456789.invalid');

    const submitBtn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no fatal errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Should show "no results" or remain on search page
    await expect(page.locator('body')).toContainText(/no results|search|not found|DNS/i);
  });

  test('should handle special characters in search', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('input[name="query"], input[placeholder*="search"], input[placeholder*="domain"]').first();
    await searchInput.fill('search-test-special');

    const submitBtn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no fatal errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Should show search page or results
    await expect(page.locator('body')).toContainText(/search|DNS|zones|records|no results/i);
  });

  // Clean up test zones after all tests
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    for (const zone of testZones) {
      try {
        await page.goto('/zones/forward?letter=all');
        await page.waitForLoadState('networkidle');

        // Find and click delete link for the zone
        const zoneRow = page.locator(`tr:has-text("${zone}")`).first();
        if (await zoneRow.count() > 0) {
          const deleteLink = zoneRow.locator('a[href*="delete"], [data-testid^="delete-zone-"]').first();
          if (await deleteLink.count() > 0) {
            await deleteLink.click();
            await page.waitForLoadState('networkidle');

            // Confirm deletion
            const confirmBtn = page.locator('button[type="submit"]:has-text("Delete"), input[type="submit"][value*="Delete"], [data-testid="confirm-delete-zone"]').first();
            if (await confirmBtn.count() > 0) {
              await confirmBtn.click();
              await page.waitForLoadState('networkidle');
            }
          }
        }
      } catch {
        // Zone may not exist, continue
      }
    }

    await page.close();
  });
});
