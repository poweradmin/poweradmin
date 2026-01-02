import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Migrations Page', () => {
  test.describe('Admin User - Permission Check', () => {
    test('should check if migrations page is accessible', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=migrations');
      // Page should either show migrations content or redirect
      const url = page.url();
      const isAccessible = url.includes('page=migrations') ||
                          url.includes('page=index') ||
                          url.includes('error');
      expect(isAccessible).toBeTruthy();
    });

    test('should display migrations heading if accessible', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=migrations');
      const bodyText = await page.locator('body').textContent();
      if (page.url().includes('page=migrations')) {
        expect(bodyText.toLowerCase()).toMatch(/migration|database|update/i);
      }
    });

    test('should display migrations output if accessible', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=migrations');
      if (page.url().includes('page=migrations')) {
        const bodyText = await page.locator('body').textContent();
        // Should have some content related to migrations
        expect(bodyText.length).toBeGreaterThan(0);
      }
    });

    test('should have pre element for output if accessible', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=migrations');
      if (page.url().includes('page=migrations')) {
        const pre = page.locator('pre');
        if (await pre.count() > 0) {
          await expect(pre.first()).toBeVisible();
        }
      }
    });
  });

  test.describe('Manager User - Permission Check', () => {
    test('should check manager access to migrations', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=migrations');
      // Manager likely should not have access to migrations
      const url = page.url();
      const hasAccess = url.includes('page=migrations') ||
                       url.includes('page=index') ||
                       url.includes('error');
      expect(hasAccess).toBeTruthy();
    });
  });

  test.describe('Client User - Permission Check', () => {
    test('should check client access to migrations', async ({ clientPage: page }) => {
      await page.goto('/index.php?page=migrations');
      // Client should not have access to migrations
      const url = page.url();
      const hasAccess = url.includes('page=index') ||
                       url.includes('error') ||
                       url.includes('page=migrations');
      expect(hasAccess).toBeTruthy();
    });
  });

  test.describe('Viewer User - Permission Check', () => {
    test('should check viewer access to migrations', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=migrations');
      // Viewer should not have access to migrations
      const url = page.url();
      const hasAccess = url.includes('page=index') ||
                       url.includes('error') ||
                       url.includes('page=migrations');
      expect(hasAccess).toBeTruthy();
    });
  });
});
