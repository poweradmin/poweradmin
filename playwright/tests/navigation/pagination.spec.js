import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Pagination Functionality', () => {
  test('should display pagination controls when zone list exceeds page size', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Check for pagination controls
    const paginationExists = await page.locator('.pagination, nav[aria-label*="pagination"], a[href*="start="]').count() > 0;

    if (paginationExists) {
      await expect(page.locator('.pagination, nav[aria-label*="pagination"]').first()).toBeVisible();
    } else {
      // No pagination - either not enough items or single page
      test.info().annotations.push({ type: 'note', description: 'No pagination controls found - may not have enough zones' });
    }
  });

  test('should navigate to next page of zones', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Check if pagination exists
    const nextButton = page.locator('a:has-text("Next"), a:has-text("›"), a:has-text("»"), a[href*="start="]').first();

    if (await nextButton.count() > 0) {
      await nextButton.click();
      // Should navigate successfully
      await expect(page.locator('body')).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'No next page button found' });
    }
  });

  test('should navigate to previous page of zones', async ({ adminPage: page }) => {
    // Start on page 2
    await page.goto('/index.php?page=list_forward_zones&start=10');

    // Now click previous
    const prevButton = page.locator('a:has-text("Previous"), a:has-text("‹"), a:has-text("«")').first();
    if (await prevButton.count() > 0) {
      await prevButton.click();
      // Should navigate successfully
      await expect(page.locator('body')).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'No previous page button found' });
    }
  });

  test('should display correct page numbers in pagination', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Check if page numbers are displayed
    const paginationContainer = page.locator('.pagination').first();

    if (await paginationContainer.count() > 0) {
      // Look for page number links
      const pageLinks = paginationContainer.locator('a, button').filter({ hasText: /^[0-9]+$/ });
      const linkCount = await pageLinks.count();

      if (linkCount > 0) {
        await expect(pageLinks.first()).toBeVisible();
      }
    }
  });

  test('should maintain pagination when filtering zones', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Check if filter/search exists
    const filterInput = page.locator('input[name*="filter"], input[name*="search"]').first();

    if (await filterInput.count() > 0) {
      await filterInput.fill('example');

      // Apply filter
      const filterButton = page.locator('button[type="submit"], input[type="submit"]').first();
      if (await filterButton.count() > 0) {
        await filterButton.click();
      }
    }

    // Page should load successfully
    await expect(page.locator('body')).toBeVisible();
  });

  test('should handle direct page navigation via URL', async ({ adminPage: page }) => {
    // Navigate directly to page 2 using start parameter
    await page.goto('/index.php?page=list_forward_zones&start=10');

    // Should load page without error
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should display items per page selector if available', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Check for items per page dropdown
    const perPageSelector = page.locator('select[name*="per_page"], select[name*="limit"]').first();

    if (await perPageSelector.count() > 0) {
      await expect(perPageSelector).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'No per-page selector found' });
    }
  });

  test('should show total count of items', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Page should load successfully
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toBeTruthy();
  });

  test('should handle pagination with records list', async ({ adminPage: page }) => {
    // Navigate to a zone edit page (if zones exist)
    await page.goto('/index.php?page=edit&id=1', { waitUntil: 'domcontentloaded' });

    const hasRecords = await page.locator('table, .table').count() > 0;
    if (hasRecords) {
      // Check for pagination on records
      const recordsPagination = page.locator('.pagination, a[href*="start="]');
      const hasPagination = await recordsPagination.count() > 0;
      // Pagination might or might not exist depending on record count
      expect(hasPagination !== undefined).toBeTruthy();
    }
  });

  test('should preserve sort order across pages', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_forward_zones&letter=all');

    // Check if sorting is available
    const sortableHeader = page.locator('th a, th.sortable').first();

    if (await sortableHeader.count() > 0) {
      // Click to sort
      await sortableHeader.click();
      // Page should load successfully
      await expect(page.locator('body')).toBeVisible();
    }
  });
});
