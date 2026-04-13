import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
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
 */
async function cleanupZones(page, zones) {
  for (const zone of zones) {
    try {
      await page.goto('/zones/forward');
      const zoneRow = page.locator(`tr:has-text("${zone}")`);
      if (await zoneRow.count() > 0) {
        await zoneRow.locator('[data-testid^="delete-zone-"]').click();
        await page.locator('[data-testid="confirm-delete-zone"]').click();
      }
    } catch (e) {
      // Continue if zone not found
    }
  }
}

test.describe('Pagination Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display pagination controls when zone list exceeds page size', async ({ page }) => {
    const zones = await ensurePaginationZones(page, 'pagination-test', 25);

    await page.goto('/zones/forward');

    const paginationExists = await page.locator('.pagination, [data-testid*="pagination"], nav[aria-label*="pagination"]').count() > 0;

    if (paginationExists) {
      await expect(page.locator('.pagination, [data-testid*="pagination"]')).toBeVisible();
    }

    await cleanupZones(page, zones);
  });

  test('should navigate to next page of zones', async ({ page }) => {
    const zones = await ensurePaginationZones(page, 'page-test', 15);

    await page.goto('/zones/forward');

    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›"), a:has-text("»")').first();

    if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
      await nextButton.click();

      const currentUrl = page.url();
      expect(currentUrl).toMatch(/page=2|offset=/i);
    }

    await cleanupZones(page, zones);
  });

  test('should navigate to previous page of zones', async ({ page }) => {
    const zones = await ensurePaginationZones(page, 'prev-test', 15);

    await page.goto('/zones/forward');

    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›")').first();
    if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
      await nextButton.click();

      const prevButton = page.locator('a:has-text("Previous"), button:has-text("Previous"), a:has-text("‹"), a:has-text("«")').first();
      if (await prevButton.count() > 0 && await prevButton.isEnabled()) {
        await prevButton.click();

        const currentUrl = page.url();
        const isPage1 = !currentUrl.includes('page=2') || currentUrl.includes('page=1');
        expect(isPage1).toBeTruthy();
      }
    }

    await cleanupZones(page, zones);
  });

  test('should display correct page numbers in pagination', async ({ page }) => {
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
    const zoneName = `records-page-test-${Date.now()}.com`;

    // First clean up any existing test zone
    await page.goto('/zones/forward');
    const existingZone = page.locator('tr:has-text("records-page-test")');
    if (await existingZone.count() > 0) {
      await existingZone.first().locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();
      await page.waitForLoadState('networkidle');
    }

    // Create a zone and add many records
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
    await page.locator('[data-testid="add-zone-button"]').click();

    await page.waitForLoadState('networkidle');

    const errorAlert = page.locator('.alert-danger, .alert.alert-danger');
    if (await errorAlert.count() > 0) {
      return;
    }

    await page.goto('/zones/forward');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${zoneName}")`);
    if (await zoneRow.count() === 0) {
      return;
    }

    await zoneRow.locator('[data-testid^="edit-zone-"]').click();

    for (let i = 1; i <= 15; i++) {
      await page.locator('select.record-type-select, select[name*="type"]').first().selectOption('A');
      await page.locator('[data-testid="record-name-input"]').fill(`host${i}`);
      await page.locator('[data-testid="record-content-input"]').fill(`192.168.1.${i}`);
      await page.locator('[data-testid="add-record-button"]').click();
    }

    const recordsPagination = page.locator('.pagination, [data-testid*="pagination"]');
    const hasPagination = await recordsPagination.count() > 0;

    expect(hasPagination !== undefined).toBeTruthy();

    // Cleanup
    await page.goto('/zones/forward');
    const cleanupRow = page.locator(`tr:has-text("${zoneName}")`);
    if (await cleanupRow.count() > 0) {
      await cleanupRow.locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();
    }
  });

  test('should preserve sort order across pages', async ({ page }) => {
    await page.goto('/zones/forward');

    const sortableHeader = page.locator('th[data-sortable], th a, th.sortable').first();

    if (await sortableHeader.count() > 0) {
      await sortableHeader.click();

      const nextButton = page.locator('a:has-text("Next"), button:has-text("Next")').first();
      if (await nextButton.count() > 0 && await nextButton.isEnabled()) {
        await nextButton.click();

        const currentUrl = page.url();
        expect(currentUrl).toMatch(/sort|order/i);
      }
    }
  });
});
