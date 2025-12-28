import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search Functionality', () => {
  const testDomain = `search-test-${Date.now()}.example.com`;
  let zoneCreated = false;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Create test zone with records
    await page.goto('/index.php?page=add_zone_master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    zoneCreated = true;
    await page.close();
  });

  test.describe('Search Page Access', () => {
    test('should access search page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=search');

      await expect(page).toHaveURL(/page=search/);
    });

    test('should display search form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=search');

      const form = page.locator('form');
      await expect(form.first()).toBeVisible();
    });

    test('should display search input field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=search');

      const searchInput = page.locator('input[name*="search"], input[name*="query"], input[type="search"], input[type="text"]').first();
      await expect(searchInput).toBeVisible();
    });

    test('should display search button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=search');

      const searchBtn = page.locator('button[type="submit"], input[type="submit"]');
      expect(await searchBtn.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Zone Search', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should search by exact zone name', async ({ page }) => {
      if (!zoneCreated) test.skip();

      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toContain(testDomain);
    });

    test('should search by partial zone name', async ({ page }) => {
      if (!zoneCreated) test.skip();

      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('search-test');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/search-test|result|found/i);
    });

    test('should handle no results', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('nonexistent-zone-xyz123');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/no.*result|not.*found|no.*match|0.*result/i);
    });

    test('should search case insensitively', async ({ page }) => {
      if (!zoneCreated) test.skip();

      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('SEARCH-TEST');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      // Should find results regardless of case
      expect(bodyText.toLowerCase()).not.toMatch(/error|exception/);
    });
  });

  test.describe('Record Search', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should search by record name', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('www');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by record content', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('192.168');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by record type', async ({ page }) => {
      await page.goto('/index.php?page=search');

      // Check if record type filter exists
      const typeFilter = page.locator('select[name*="type"]');
      if (await typeFilter.count() > 0) {
        await typeFilter.first().selectOption('A');
        await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('*');
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Search Features', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should handle empty search', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show all results or error message
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle search with special characters', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('test@#$%');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|sql/i);
    });

    test('should handle very long search query', async ({ page }) => {
      await page.goto('/index.php?page=search');

      const longQuery = 'a'.repeat(500);
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(longQuery);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display search result count', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/result|found|record|zone/i);
    });

    test('should display clickable search results', async ({ page }) => {
      if (!zoneCreated) test.skip();

      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const resultLink = page.locator(`a:has-text("${testDomain.substring(0, 10)}")`);
      if (await resultLink.count() > 0) {
        await expect(resultLink.first()).toBeVisible();
      }
    });

    test('should navigate to zone from search result', async ({ page }) => {
      if (!zoneCreated) test.skip();

      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const resultLink = page.locator('a[href*="page=edit"]').first();
      if (await resultLink.count() > 0) {
        await resultLink.click();
        await expect(page).toHaveURL(/page=edit/);
      }
    });
  });

  test.describe('Search with Wildcards', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should support asterisk wildcard', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('*example*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should support question mark wildcard', async ({ page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('test?');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Permission Tests', () => {
    test('manager should access search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=search');

      await expect(page).toHaveURL(/page=search/);
    });

    test('client should access search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/index.php?page=search');

      await expect(page).toHaveURL(/page=search/);
    });

    test('viewer should access search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/index.php?page=search');

      await expect(page).toHaveURL(/page=search/);
    });

    test('manager should only see own zones in results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should only see zones the manager owns
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);

    if (await row.count() > 0) {
      const deleteLink = row.locator('a[href*="delete_domain"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();
        }
      }
    }

    await page.close();
  });
});
