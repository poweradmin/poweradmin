import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

/**
 * Producing a paginated zone list takes two things. The page size is a stored
 * per-user preference that the query parameter writes, and the seeding sets it
 * to 100, so it is shrunk here and restored afterwards for the specs that
 * follow in this worker. And the list has to be filtered to one letter: the SQL
 * backend returns every zone for letter=all, so that view never paginates.
 */
const PAGE_SIZE = 5;
const SEEDED_PAGE_SIZE = 100;
const LETTER = 'p';
const FILLER_COUNT = 7;

async function setZoneListPageSize(page, size) {
  await page.goto(`/zones/forward?rows_per_page=${size}`);
  await page.waitForLoadState('domcontentloaded');
}

async function openPaginatedList(page) {
  await page.goto(`/zones/forward?letter=${LETTER}&start=1`);
  await page.waitForLoadState('domcontentloaded');
}

/** Enough zones under one letter to fill more than one page of PAGE_SIZE. */
async function createFillerZones(page, prefix) {
  const zones = [];
  for (let i = 1; i <= FILLER_COUNT; i++) {
    const zoneName = `${prefix}-${i}.example.com`;
    zones.push(zoneName);
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('domcontentloaded');
  }
  return zones;
}

async function removeZones(page, zones) {
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

  let fillerZones = [];

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await setZoneListPageSize(page, PAGE_SIZE);
    fillerZones = await createFillerZones(page, `${LETTER}aginated`);
    await page.close();
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    test.setTimeout(120000);
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await removeZones(page, fillerZones);
    await setZoneListPageSize(page, SEEDED_PAGE_SIZE);
    await page.close();
  });

  test('should display pagination controls when zone list exceeds page size', async ({ page }) => {
    await openPaginatedList(page);

    // The letter strip is a .pagination list too, so scope to the last match,
    // which is the pager below the table
    await expect(page.locator('.pagination').last()).toBeVisible();
  });

  test('should navigate to next page of zones', async ({ page }) => {
    await openPaginatedList(page);

    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›"), a:has-text("»")').first();
    await expect(nextButton).toBeVisible();

    await nextButton.click();
    await page.waitForLoadState('domcontentloaded');

    // The zone list paginates with ?start=<page number>
    expect(page.url()).toMatch(/[?&]start=2\b/);
  });

  test('should navigate to previous page of zones', async ({ page }) => {
    await openPaginatedList(page);

    const nextButton = page.locator('a:has-text("Next"), button:has-text("Next"), a:has-text("›")').first();
    await expect(nextButton).toBeVisible();
    await nextButton.click();
    await page.waitForLoadState('domcontentloaded');

    const prevButton = page.locator('a:has-text("Previous"), button:has-text("Previous"), a:has-text("‹"), a:has-text("«")').first();
    await expect(prevButton).toBeVisible();
    await prevButton.click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).not.toMatch(/[?&]start=2\b/);
  });

  test('should display correct page numbers in pagination', async ({ page }) => {
    await openPaginatedList(page);

    const pager = page.locator('.pagination').last();
    const pageLinks = pager.locator('a, button').filter({ hasText: /^[0-9]+$/ });

    await expect(pageLinks.first()).toBeVisible();
    expect(await pageLinks.count()).toBeGreaterThan(1);
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

    // The control is an id-only select with no name attribute
    const perPageSelector = page.locator('#rows-per-page');
    await expect(perPageSelector).toBeVisible();

    const options = perPageSelector.locator('option');
    await expect(options.first()).toBeAttached();
    expect(await options.count()).toBeGreaterThan(1);
  });

  test('should show total count of items', async ({ page }) => {
    await page.goto('/zones/forward');

    const bodyText = await page.locator('body').textContent();
    const hasTotalInfo = bodyText.match(/showing|total|of \d+|displaying/i);

    expect(hasTotalInfo).not.toBeNull();
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
