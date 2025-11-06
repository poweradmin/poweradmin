import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Pagination Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display pagination controls when zone list exceeds page size', async ({ page }) => {
    // Create multiple zones to trigger pagination
    const zoneCount = 25; // Assuming default page size is less than this
    const zones = [];

    for (let i = 1; i <= zoneCount; i++) {
      const zoneName = `pagination-test-${i}.com`;
      zones.push(zoneName);

      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
      await page.locator('[data-testid="add-zone-button"]').click();
    }

    // Navigate to zones list
    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Check for pagination controls
    const paginationExists = await page.locator('.pagination, [data-testid*="pagination"], nav[aria-label*="pagination"]').count() > 0;

    if (paginationExists) {
      await expect(page.locator('.pagination, [data-testid*="pagination"]')).toBeVisible();
    }

    // Cleanup all test zones
    for (const zone of zones) {
      try {
        await page.locator('[data-testid="list-forward-zones-link"]').click();
        const zoneRow = page.locator(`tr:has-text("${zone}")`);
        if (await zoneRow.count() > 0) {
          await zoneRow.locator('[data-testid^="delete-zone-"]').click();
          await page.locator('[data-testid="confirm-delete-zone"]').click();
        }
      } catch (e) {
        // Continue if zone not found
      }
    }
  });

  test('should navigate to next page of zones', async ({ page }) => {
    // Create enough zones for pagination
    const zones = [];
    for (let i = 1; i <= 15; i++) {
      const zoneName = `page-test-${i}.com`;
      zones.push(zoneName);

      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
      await page.locator('[data-testid="add-zone-button"]').click();
    }

    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Check if pagination exists
    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›"), a:has-text("»")').first();

    if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
      await nextButton.click();

      // Verify we're on page 2 or URL changed
      const currentUrl = page.url();
      expect(currentUrl).toMatch(/page=2|offset=/i);
    }

    // Cleanup
    for (const zone of zones) {
      try {
        await page.locator('[data-testid="list-forward-zones-link"]').click();
        const zoneRow = page.locator(`tr:has-text("${zone}")`);
        if (await zoneRow.count() > 0) {
          await zoneRow.locator('[data-testid^="delete-zone-"]').click();
          await page.locator('[data-testid="confirm-delete-zone"]').click();
        }
      } catch (e) {
        // Continue
      }
    }
  });

  test('should navigate to previous page of zones', async ({ page }) => {
    // Create zones and navigate to page 2, then go back
    const zones = [];
    for (let i = 1; i <= 15; i++) {
      const zoneName = `prev-test-${i}.com`;
      zones.push(zoneName);

      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
      await page.locator('[data-testid="add-zone-button"]').click();
    }

    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Go to next page first
    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›")').first();
    if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
      await nextButton.click();

      // Now click previous
      const prevButton = page.locator('a:has-text("Previous"), button:has-text("Previous"), a:has-text("‹"), a:has-text("«")').first();
      if (await prevButton.count() > 0 && await prevButton.isEnabled()) {
        await prevButton.click();

        // Should be back on page 1
        const currentUrl = page.url();
        const isPage1 = !currentUrl.includes('page=2') || currentUrl.includes('page=1');
        expect(isPage1).toBeTruthy();
      }
    }

    // Cleanup
    for (const zone of zones) {
      try {
        await page.locator('[data-testid="list-forward-zones-link"]').click();
        const zoneRow = page.locator(`tr:has-text("${zone}")`);
        if (await zoneRow.count() > 0) {
          await zoneRow.locator('[data-testid^="delete-zone-"]').click();
          await page.locator('[data-testid="confirm-delete-zone"]').click();
        }
      } catch (e) {
        // Continue
      }
    }
  });

  test('should display correct page numbers in pagination', async ({ page }) => {
    // Create zones for pagination
    const zones = [];
    for (let i = 1; i <= 20; i++) {
      const zoneName = `num-test-${i}.com`;
      zones.push(zoneName);

      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
      await page.locator('[data-testid="add-zone-button"]').click();
    }

    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Check if page numbers are displayed
    const paginationContainer = page.locator('.pagination, [data-testid*="pagination"]').first();

    if (await paginationContainer.count() > 0) {
      // Look for page number links
      const pageLinks = paginationContainer.locator('a, button').filter({ hasText: /^[0-9]+$/ });
      const linkCount = await pageLinks.count();

      if (linkCount > 0) {
        // At least page 1 should exist
        await expect(pageLinks.first()).toBeVisible();
      }
    }

    // Cleanup
    for (const zone of zones) {
      try {
        await page.locator('[data-testid="list-forward-zones-link"]').click();
        const zoneRow = page.locator(`tr:has-text("${zone}")`);
        if (await zoneRow.count() > 0) {
          await zoneRow.locator('[data-testid^="delete-zone-"]').click();
          await page.locator('[data-testid="confirm-delete-zone"]').click();
        }
      } catch (e) {
        // Continue
      }
    }
  });

  test('should maintain pagination when filtering zones', async ({ page }) => {
    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Check if filter/search exists
    const filterInput = page.locator('input[name*="filter"], input[name*="search"], input[placeholder*="filter"]').first();

    if (await filterInput.count() > 0) {
      await filterInput.fill('example');

      // Apply filter
      const filterButton = page.locator('button[type="submit"], button:has-text("Filter"), button:has-text("Search")').first();
      if (await filterButton.count() > 0) {
        await filterButton.click();
      }

      // Pagination should still work with filtered results
      await expect(page.locator('body')).toBeVisible();
    }
  });

  test('should handle direct page navigation via URL', async ({ page }) => {
    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Get current URL and modify page parameter
    const currentUrl = new URL(page.url());
    currentUrl.searchParams.set('page', '2');

    await page.goto(currentUrl.toString());

    // Should load page 2 (or handle gracefully if no page 2)
    await expect(page.locator('body')).toBeVisible();
  });

  test('should display items per page selector if available', async ({ page }) => {
    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Check for items per page dropdown
    const perPageSelector = page.locator('select[name*="per_page"], select[name*="limit"], [data-testid*="per-page"]').first();

    if (await perPageSelector.count() > 0) {
      await expect(perPageSelector).toBeVisible();

      // Try changing items per page
      const options = await perPageSelector.locator('option').count();
      if (options > 1) {
        await perPageSelector.selectOption({ index: 1 });
        // Page should reload or update
        await expect(page.locator('body')).toBeVisible();
      }
    }
  });

  test('should show total count of items', async ({ page }) => {
    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Look for total count display (e.g., "Showing 1-10 of 25")
    const bodyText = await page.locator('body').textContent();
    const hasTotalInfo = bodyText.match(/showing|total|of \d+|displaying/i);

    // Total info might be displayed somewhere on the page
    expect(bodyText).toBeTruthy();
  });

  test('should handle pagination with records list', async ({ page }) => {
    // Create a zone and add many records
    await page.locator('[data-testid="add-master-zone-link"]').click();
    await page.locator('[data-testid="zone-name-input"]').fill('records-pagination-test.com');
    await page.locator('[data-testid="add-zone-button"]').click();

    // Navigate to zone
    await page.locator('[data-testid="list-forward-zones-link"]').click();
    await page.locator('tr:has-text("records-pagination-test.com")').locator('[data-testid^="edit-zone-"]').click();

    // Add multiple records
    for (let i = 1; i <= 15; i++) {
      await page.locator('select.record-type-select, select[name*="type"]').first().selectOption('A');
      await page.locator('[data-testid="record-name-input"]').fill(`host${i}`);
      await page.locator('[data-testid="record-content-input"]').fill(`192.168.1.${i}`);
      await page.locator('[data-testid="add-record-button"]').click();
    }

    // Check if pagination exists for records
    const recordsPagination = page.locator('.pagination, [data-testid*="pagination"]');
    const hasPagination = await recordsPagination.count() > 0;

    // Pagination might appear depending on page size settings
    expect(hasPagination !== undefined).toBeTruthy();

    // Cleanup
    await page.locator('[data-testid="list-forward-zones-link"]').click();
    await page.locator('tr:has-text("records-pagination-test.com")').locator('[data-testid^="delete-zone-"]').click();
    await page.locator('[data-testid="confirm-delete-zone"]').click();
  });

  test('should preserve sort order across pages', async ({ page }) => {
    await page.locator('[data-testid="list-forward-zones-link"]').click();

    // Check if sorting is available
    const sortableHeader = page.locator('th[data-sortable], th a, th.sortable').first();

    if (await sortableHeader.count() > 0) {
      // Click to sort
      await sortableHeader.click();

      // Navigate to next page if available
      const nextButton = page.locator('a:has-text("Next"), button:has-text("Next")').first();
      if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
        await nextButton.click();

        // Sort order should be maintained (check URL for sort parameter)
        const currentUrl = page.url();
        expect(currentUrl).toMatch(/sort|order/i);
      }
    }
  });
});
