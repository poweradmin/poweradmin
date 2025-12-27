import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Set up test zones if they don't exist
    const testZones = ['search-test1.com', 'search-test2.org', 'search-test-special.net'];

    for (const zone of testZones) {
      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill(zone);
      await page.locator('[data-testid="add-zone-button"]').click();

      // Add a test record
      await page.locator('[data-testid="list-zones-link"]').click();
      await page.locator(`tr:has-text("${zone}")`).locator('[data-testid^="edit-zone-"]').click();

      await page.locator('[data-testid="record-type-select"]').selectOption('A');
      await page.locator('[data-testid="record-name-input"]').fill('www');
      await page.locator('[data-testid="record-content-input"]').fill('192.168.1.10');
      await page.locator('[data-testid="add-record-button"]').click();
    }
  });

  test('should search for zones by exact name', async ({ page }) => {
    // Click on Search in navigation or use search card
    await page.getByText('Search').click();

    // Fill in search input
    await page.locator('input[name*="search"], input[placeholder*="search"]').fill('search-test1.com');

    // Submit search
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Verify results
    await expect(page.locator('table, .results, [class*="search"]')).toBeVisible();
    await expect(page.getByText('search-test1.com')).toBeVisible();
  });

  test('should search for zones by partial name', async ({ page }) => {
    await page.locator('[data-testid="search-link"]').click();
    await page.locator('[data-testid="search-input"]').fill('search-test');
    await page.locator('[data-testid="search-button"]').click();

    await expect(page.locator('[data-testid="search-results"]')).toBeVisible();
    await expect(page.getByText('search-test1.com')).toBeVisible();
    await expect(page.getByText('search-test2.org')).toBeVisible();
    await expect(page.getByText('search-test-special.net')).toBeVisible();
  });

  test('should search for records by content', async ({ page }) => {
    await page.locator('[data-testid="search-link"]').click();
    await page.locator('[data-testid="search-input"]').fill('192.168.1.10');
    await page.locator('[data-testid="search-type-select"]').selectOption('records');
    await page.locator('[data-testid="search-button"]').click();

    await expect(page.locator('[data-testid="search-results"]')).toBeVisible();
    await expect(page.getByText('www.search-test1.com')).toBeVisible();
    await expect(page.getByText('www.search-test2.org')).toBeVisible();
    await expect(page.getByText('www.search-test-special.net')).toBeVisible();
  });

  test('should handle searches with no results', async ({ page }) => {
    await page.locator('[data-testid="search-link"]').click();
    await page.locator('[data-testid="search-input"]').fill('nonexistent-domain.com');
    await page.locator('[data-testid="search-button"]').click();

    await expect(page.locator('[data-testid="no-results-message"]')).toBeVisible();
    await expect(page.locator('[data-testid="no-results-message"]')).toContainText('No matches found');
  });

  test('should handle special characters in search', async ({ page }) => {
    await page.locator('[data-testid="search-link"]').click();
    await page.locator('[data-testid="search-input"]').fill('search-test-special');
    await page.locator('[data-testid="search-button"]').click();

    await expect(page.locator('[data-testid="search-results"]')).toBeVisible();
    await expect(page.getByText('search-test-special.net')).toBeVisible();
  });

  // Clean up test zones after all tests
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    const testZones = ['search-test1.com', 'search-test2.org', 'search-test-special.net'];

    for (const zone of testZones) {
      await page.locator('[data-testid="list-zones-link"]').click();
      await page.locator(`tr:has-text("${zone}")`).locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();
    }

    await page.close();
  });
});
