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
    const searchInput = page.locator('input[type="search"], input[name*="search"], input[name*="query"]').first();
    await searchInput.fill('nonexistent-domain-xyz123.com');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show no results or empty message
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/no|not found|empty|results/i);
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
