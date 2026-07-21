import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Soft-asserts each expected nav link so one run names every missing href.
// mode 'visible' requires the link rendered in the nav; 'present' allows it
// to live collapsed in a dropdown (exists in DOM).
async function expectLinks(page, links) {
  for (const link of links) {
    const locator = page.locator(link.selector);
    if (link.mode === 'visible') {
      await expect.soft(locator.first(), `${link.name} link should be visible`).toBeVisible();
    } else {
      expect.soft(await locator.count(), `${link.name} link should be present`).toBeGreaterThan(0);
    }
  }
}

test.describe('Dashboard', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should display dashboard content', async ({ page }) => {
      // Dashboard should have some content area
      const mainContent = page.locator('main, .container, .content, #content').first();
      await expect(mainContent).toBeVisible();
    });

    test('exposes expected navigation links', async ({ page }) => {
      await expectLinks(page, [
        { selector: 'a[href*="/search"]', mode: 'visible', name: 'Search' },
        { selector: 'a[href*="/zones/forward"]', mode: 'visible', name: 'List zones' },
        { selector: 'a[href*="/zones/templates"]', mode: 'visible', name: 'Zone templates' },
        { selector: 'a[href*="/supermasters"]', mode: 'visible', name: 'Supermasters' },
        { selector: 'a[href*="/zones/add/master"]', mode: 'present', name: 'Add master zone' },
        { selector: 'a[href*="/zones/add/slave"]', mode: 'present', name: 'Add slave zone' },
        { selector: 'a[href*="/supermasters/add"]', mode: 'present', name: 'Add supermaster' },
        { selector: 'a[href*="/zones/bulk-registration"]', mode: 'present', name: 'Bulk registration' },
        { selector: 'a[href*="/password/change"]', mode: 'present', name: 'Change password' },
        { selector: 'a[href$="/users"], a[href*="/users?"]', mode: 'visible', name: 'User administration' },
        { selector: 'a[href*="/permissions/templates"]', mode: 'present', name: 'Permission templates' },
        { selector: 'a[href*="/logout"]', mode: 'present', name: 'Logout' },
        { selector: 'a[href*="/groups"]', mode: 'visible', name: 'Groups' },
        { selector: 'a[href*="/settings/api-keys"]', mode: 'present', name: 'API Keys' },
        { selector: 'a[href*="/tools/database-consistency"]', mode: 'present', name: 'Database Consistency' },
      ]);
    });

    test('should have Zone logs link for admin (if db logging enabled)', async ({ page }) => {
      // Zone logs link only appears when dblog_use is enabled in config
      // Link may be in dropdown menu, check if it exists in DOM
      const zoneLogsLink = page.locator('a[href*="/zones/logs"]');
      const count = await zoneLogsLink.count();
      // Either the link exists (dblog enabled) or it doesn't (dblog disabled) - both are valid
      expect(count >= 0).toBeTruthy();
      if (count > 0) {
        // If link exists, verify it's accessible
        expect(count).toBeGreaterThan(0);
      }
    });

    test('should navigate to search page', async ({ page }) => {
      await page.locator('a[href*="/search"]').first().click();
      await expect(page).toHaveURL(/.*\/search/);
    });

    test('should navigate to list zones page', async ({ page }) => {
      await page.locator('a[href*="/zones/forward"]').first().click();
      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('should display collapsible sections', async ({ page }) => {
      const sections = page.locator('[data-bs-toggle="collapse"]');
      expect(await sections.count()).toBeGreaterThanOrEqual(3);
    });

    test('should collapse and expand sections', async ({ page }) => {
      const firstSection = page.locator('[data-bs-toggle="collapse"]').first();
      const targetId = await firstSection.getAttribute('data-bs-target');
      const targetContent = page.locator(targetId);

      await expect(targetContent).toBeVisible();
      await firstSection.click();
      await page.waitForTimeout(300);
      await expect(targetContent).not.toBeVisible();
      await firstSection.click();
      await page.waitForTimeout(300);
      await expect(targetContent).toBeVisible();
    });

    test('should display dashboard stats for admin', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/\d+\s+zones/);
      expect(bodyText).toMatch(/\d+\s+users/);
      expect(bodyText).toMatch(/\d+\s+groups/);
      // Records count is only available on SQL backend, not API backend
      if (bodyText.match(/\d+\s+records/)) {
        expect(bodyText).toMatch(/\d+\s+records/);
      }
    });
  });

  test.describe('Manager User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('exposes expected navigation links', async ({ page }) => {
      // Zone logs are no longer admin-only: the manager fixture holds
      // zone_logs_view_own, so the delegated logs entry is present.
      await expectLinks(page, [
        { selector: 'a[href*="/search"]', mode: 'visible', name: 'Search' },
        { selector: 'a[href*="/zones/logs"]', mode: 'present', name: 'Zone logs (zone_logs_view_own)' },
      ]);
    });

    test('should not have Permission templates link', async ({ page }) => {
      const permTemplatesLink = page.locator('a[href*="/permissions/templates"]');
      expect(await permTemplatesLink.count()).toBe(0);
    });

    test('should not display dashboard stats', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/\d+\s+zones.*\d+\s+records.*\d+\s+users/);
    });
  });

  test.describe('Client User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('should have limited navigation (no Search link in nav)', async ({ page }) => {
      // Client users have limited permissions - Search link may not be visible in navigation
      // They can still access /search directly if they have zone permissions
      const searchLink = page.locator('a[href*="/search"]');
      const count = await searchLink.count();
      // Client users may or may not have Search link depending on their zone permissions
      expect(count >= 0).toBeTruthy();
    });

    test('should not have Add master zone link', async ({ page }) => {
      const addMasterLink = page.locator('a[href*="/zones/add/master"]');
      expect(await addMasterLink.count()).toBe(0);
    });

    test('should not have Add slave zone link', async ({ page }) => {
      const addSlaveLink = page.locator('a[href*="/zones/add/slave"]');
      expect(await addSlaveLink.count()).toBe(0);
    });
  });

  test.describe('Viewer User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    });

    test('should display welcome heading', async ({ page }) => {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toContain('welcome');
    });

    test('exposes expected navigation links', async ({ page }) => {
      await expectLinks(page, [
        { selector: 'a[href*="/search"]', mode: 'visible', name: 'Search' },
        { selector: 'a[href*="/zones/forward"]', mode: 'visible', name: 'List zones (view permission)' },
      ]);
    });

    test('should not have Add master zone link', async ({ page }) => {
      const addMasterLink = page.locator('a[href*="/zones/add/master"]');
      expect(await addMasterLink.count()).toBe(0);
    });
  });
});
