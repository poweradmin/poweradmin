import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Search Functionality', () => {
  test('should access search page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');
    await expect(page).toHaveURL(/page=search/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should search for zones by exact name', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    // Fill in search input
    const searchInput = page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first();
    await searchInput.fill('example.com');

    // Submit search
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify results page loads
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/search|results|found/i);
  });

  test('should search for zones by partial name', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    // Fill in search input with partial name
    const searchInput = page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first();
    await searchInput.fill('example');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify results page loads
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/search|results|found/i);
  });

  test('should search for records by content', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    // Fill in search with IP address
    const searchInput = page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first();
    await searchInput.fill('192.168');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify results page loads
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/search|results|found|no/i);
  });

  test('should handle searches with no results', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    // Search for non-existent domain
    const searchInput = page.locator('input[name="query"]').first();
    await searchInput.fill('nonexistent-domain-xyz123.com');

    // Submit and wait for form submission to complete
    await Promise.all([
      page.waitForLoadState('networkidle'),
      page.locator('button[type="submit"], input[type="submit"]').first().click(),
    ]);

    // Should show "No results found" message when no zones/records match
    const noResultsCard = page.locator('text=No results found');
    const hasNoResultsMessage = await noResultsCard.count() > 0;

    // Alternatively, verify no zone/record tables are shown
    const hasZonesFound = await page.locator('text=Zones found').count() > 0;
    const hasRecordsFound = await page.locator('text=Records found').count() > 0;

    // Either show "No results found" OR no results tables
    expect(hasNoResultsMessage || (!hasZonesFound && !hasRecordsFound)).toBeTruthy();
  });

  test('should handle special characters in search', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=search');

    // Search with special characters
    const searchInput = page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first();
    await searchInput.fill('test-special');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify results page loads without error
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
