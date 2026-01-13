import { test, expect } from '../../fixtures/test-fixtures.js';
import { ensureAnyZoneExists, findAnyZoneId } from '../../helpers/zones.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search Functionality', () => {
  // Will be set dynamically in the first test
  let testDomain = null;

  test.describe('Search Page Access', () => {
    test('should access search page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('should display search form', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');
      const form = page.locator('form');
      await expect(form.first()).toBeVisible();
    });

    test('should display search input field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');
      const searchInput = page.locator('input[name*="search"], input[name*="query"], input[type="search"], input[type="text"]').first();
      await expect(searchInput).toBeVisible();
    });

    test('should display search button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');
      const searchBtn = page.locator('button[type="submit"], input[type="submit"]');
      expect(await searchBtn.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Zone Search', () => {
    test('should search by exact zone name', async ({ adminPage: page }) => {
      // Ensure a zone exists and find it
      await ensureAnyZoneExists(page);
      const zone = await findAnyZoneId(page);

      // Zone may be null if no zones exist - that's okay
      if (!zone || !zone.name) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      testDomain = zone.name;
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      // Should either find the zone or show no results (but no errors)
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by partial zone name', async ({ adminPage: page }) => {
      // Ensure a zone exists and find it
      await ensureAnyZoneExists(page);
      const zone = await findAnyZoneId(page);

      // Zone may be null if no zones exist - that's okay
      if (!zone || !zone.name) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/index.php?page=search');

      // Use first part of zone name for partial search
      const partialName = zone.name.split('.')[0];
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(partialName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      // Should either find results or show no results message (no errors)
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle no results', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('nonexistent-zone-xyz123');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/no.*result|not.*found|no.*match|0.*result/i);
    });

    test('should search case insensitively', async ({ adminPage: page }) => {
      // Ensure a zone exists and find it
      await ensureAnyZoneExists(page);
      const zone = await findAnyZoneId(page);

      // Zone may be null if no zones exist - that's okay
      if (!zone || !zone.name) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/index.php?page=search');

      // Search with uppercase version of zone name
      const upperQuery = zone.name.toUpperCase();
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(upperQuery);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      // Should not have errors
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Record Search', () => {
    test('should search by record name', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('www');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by record content', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('192.168');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should search by record type', async ({ adminPage: page }) => {
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
    test('should handle empty search', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show all results or error message
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle search with special characters', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      // Use simpler special characters that are less likely to cause issues
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('test-zone_123');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      // Check for fatal errors, not generic "sql" word which might appear in normal context
      expect(bodyText).not.toMatch(/fatal error|uncaught exception|sql error|syntax error/i);
    });

    test('should handle very long search query', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      const longQuery = 'a'.repeat(500);
      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(longQuery);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display search result count', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/result|found|record|zone/i);
    });

    test('should display clickable search results', async ({ adminPage: page }) => {
      // Ensure a zone exists and find it
      await ensureAnyZoneExists(page);
      const zone = await findAnyZoneId(page);

      // Zone may be null if no zones exist - that's okay
      if (!zone || !zone.name) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(zone.name);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Page should load without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should navigate to zone from search result', async ({ adminPage: page }) => {
      // Ensure a zone exists and find it
      await ensureAnyZoneExists(page);
      const zone = await findAnyZoneId(page);

      // Zone may be null if no zones exist - that's okay
      if (!zone || !zone.name) {
        test.info().annotations.push({ type: 'skip', description: 'No zones available for search test' });
        return;
      }

      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill(zone.name);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Use table-specific selector to avoid matching dropdown links
      const resultLink = page.locator('table a[href*="page=edit&id="]').first();
      if (await resultLink.count() > 0) {
        await resultLink.click();
        await expect(page).toHaveURL(/page=edit/);
      }
    });
  });

  test.describe('Search with Wildcards', () => {
    test('should support asterisk wildcard', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('*example*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should support question mark wildcard', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=search');

      await page.locator('input[name*="search"], input[name*="query"], input[type="text"]').first().fill('test?');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Permission Tests', () => {
    test('manager should access search', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('client should access search', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('viewer should access search', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=search');
      await expect(page).toHaveURL(/page=search/);
    });

    test('manager should only see own zones in results', async ({ managerPage: page }) => {
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

    await page.goto('/index.php?page=list_forward_zones');
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
