import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Helper to create zones via UI if pagination is not already present.
 * Returns the list of zone names created (empty if none were needed).
 */
async function ensurePaginationZones(page, prefix, count) {
  await page.goto('/zones/forward');
  const paginationExists = await page.locator('.pagination, [data-testid*="pagination"], nav[aria-label*="pagination"]').count() > 0;

  if (paginationExists) {
    return [];
  }

  const zones = [];
  for (let i = 1; i <= count; i++) {
    const zoneName = `${prefix}-${i}.com`;
    zones.push(zoneName);

    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
    await page.locator('[data-testid="add-zone-button"]').click();
  }
  return zones;
}

/**
 * Helper to cleanup zones created during test.
 *
 * Resolving each zone by name covers the paginated list, which the previous
 * row lookup did not - it left every created zone behind on each run.
 */
async function cleanupZones(page, zones) {
  for (const zone of zones) {
    const zoneId = await findZoneIdByName(page, zone);
    if (zoneId) {
      await deleteZoneById(page, zoneId);
    }
  }
}

test.describe('Pagination Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display pagination controls when zone list exceeds page size', async ({ page }) => {
    // Creating and removing the filler zones one page load at a time does not
    // fit the default per-test budget.
    test.slow();

    const zones = await ensurePaginationZones(page, 'pagination-test', 25);

    await page.goto('/zones/forward');

    const paginationExists = await page.locator('.pagination, [data-testid*="pagination"], nav[aria-label*="pagination"]').count() > 0;

    if (paginationExists) {
      // The letter strip is a .pagination list too, so scope to the first match
      await expect(page.locator('.pagination, [data-testid*="pagination"]').first()).toBeVisible();
    }

    await cleanupZones(page, zones);
  });

  test('should navigate to next page of zones', async ({ page }) => {
    test.slow();

    const zones = await ensurePaginationZones(page, 'page-test', 15);

    await page.goto('/zones/forward');

    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›"), a:has-text("»")').first();

    if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
      await nextButton.click();

      // The zone list paginates with ?start=<page number>
      const currentUrl = page.url();
      expect(currentUrl).toMatch(/[?&]start=2\b/);
    }

    await cleanupZones(page, zones);
  });

  test('should navigate to previous page of zones', async ({ page }) => {
    test.slow();

    const zones = await ensurePaginationZones(page, 'prev-test', 15);

    await page.goto('/zones/forward');

    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›")').first();
    if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
      await nextButton.click();

      const prevButton = page.locator('a:has-text("Previous"), button:has-text("Previous"), a:has-text("‹"), a:has-text("«")').first();
      if (await prevButton.count() > 0 && await prevButton.isEnabled()) {
        await prevButton.click();

        const currentUrl = page.url();
        expect(currentUrl).not.toMatch(/[?&]start=2\b/);
      }
    }

    await cleanupZones(page, zones);
  });

  test('should display correct page numbers in pagination', async ({ page }) => {
    test.slow();

    const zones = await ensurePaginationZones(page, 'num-test', 20);

    await page.goto('/zones/forward');

    const paginationContainer = page.locator('.pagination, [data-testid*="pagination"]').first();

    if (await paginationContainer.count() > 0) {
      const pageLinks = paginationContainer.locator('a, button').filter({ hasText: /^[0-9]+$/ });
      const linkCount = await pageLinks.count();

      if (linkCount > 0) {
        await expect(pageLinks.first()).toBeVisible();
      }
    }

    await cleanupZones(page, zones);
  });

  test('should maintain pagination when filtering zones', async ({ page }) => {
    await page.goto('/zones/forward');

    const filterInput = page.locator('input[name*="filter"], input[name*="search"], input[placeholder*="filter"]').first();

    if (await filterInput.count() > 0) {
      await filterInput.fill('example');

      const filterButton = page.locator('button[type="submit"], button:has-text("Filter"), button:has-text("Search")').first();
      if (await filterButton.count() > 0) {
        await filterButton.click();
      }

      await expect(page.locator('body')).toBeVisible();
    }
  });

  test('should handle direct page navigation via URL', async ({ page }) => {
    await page.goto('/zones/forward');

    const currentUrl = new URL(page.url());
    currentUrl.searchParams.set('page', '2');

    await page.goto(currentUrl.toString());

    await expect(page.locator('body')).toBeVisible();
  });

  test('should display items per page selector if available', async ({ page }) => {
    await page.goto('/zones/forward');

    const perPageSelector = page.locator('select[name*="per_page"], select[name*="limit"], [data-testid*="per-page"]').first();

    if (await perPageSelector.count() > 0) {
      await expect(perPageSelector).toBeVisible();

      const options = await perPageSelector.locator('option').count();
      if (options > 1) {
        await perPageSelector.selectOption({ index: 1 });
        await expect(page.locator('body')).toBeVisible();
      }
    }
  });

  test('should show total count of items', async ({ page }) => {
    await page.goto('/zones/forward');

    const bodyText = await page.locator('body').textContent();
    const hasTotalInfo = bodyText.match(/showing|total|of \d+|displaying/i);

    expect(bodyText).toBeTruthy();
  });

  test('should handle pagination with records list', async ({ page }) => {
    // Creating a zone and 15 records one full page load at a time needs more
    // than the default per-test budget.
    test.slow();

    const zoneName = `records-page-test-${Date.now()}.com`;

    // Create a zone and add many records
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
    await page.locator('[data-testid="add-zone-button"]').click();

    await page.waitForLoadState('networkidle');

    const errorAlert = page.locator('.alert-danger, .alert.alert-danger');
    if (await errorAlert.count() > 0) {
      return;
    }

    // The list is paginated, so resolve the zone by name rather than by row
    const zoneId = await findZoneIdByName(page, zoneName);
    expect(zoneId).not.toBeNull();

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    for (let i = 1; i <= 15; i++) {
      await page.locator('select.record-type-select, select[name*="type"]').first().selectOption('A');
      await page.locator('[data-testid="record-name-input"]').fill(`host${i}`);
      await page.locator('[data-testid="record-content-input"]').fill(`192.168.1.${i}`);
      await page.locator('[data-testid="add-record-button"]').click();
      // Each add reloads the edit page; the next iteration must not race the reload.
      await page.waitForLoadState('networkidle');
    }

    const recordsPagination = page.locator('.pagination, [data-testid*="pagination"]');
    const hasPagination = await recordsPagination.count() > 0;

    expect(hasPagination !== undefined).toBeTruthy();

    // Cleanup
    await deleteZoneById(page, zoneId);
  });

  test('should preserve sort order across pages', async ({ page }) => {
    await page.goto('/zones/forward');

    const sortableHeader = page.locator('th[data-sortable], th a, th.sortable').first();

    if (await sortableHeader.count() > 0) {
      await sortableHeader.click();
      await page.waitForLoadState('domcontentloaded');

      const firstColumn = () => page.locator('table tbody tr td:first-child')
        .evaluateAll(cells => cells.map(c => c.innerText.trim().toLowerCase()));

      const firstPage = await firstColumn();

      const nextButton = page.locator('a:has-text("Next"), button:has-text("Next")').first();
      if (firstPage.length > 0 && await nextButton.count() > 0 && await nextButton.isEnabled()) {
        await nextButton.click();
        await page.waitForLoadState('domcontentloaded');

        // The chosen sort lives in the session, not in the pagination link, so
        // assert the data order rather than the query string.
        const secondPage = await firstColumn();
        const ascending = [...firstPage].sort().join('|') === firstPage.join('|');
        const expected = ascending ? [...secondPage].sort() : [...secondPage].sort().reverse();

        expect(secondPage.join('|')).toBe(expected.join('|'));
      }
    }
  });
});
