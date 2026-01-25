import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

/**
 * Tests for Bulk Zone Registration functionality
 * Note: The bulk_registration page is for registering multiple ZONES, not records.
 * This test file validates the bulk zone registration feature with focus on
 * zone creation and automatic record generation.
 */
test.describe('Bulk Zone Registration', () => {
  const timestamp = Date.now();
  const testDomains = [
    `bulk-zone-a-${timestamp}.example.com`,
    `bulk-zone-b-${timestamp}.example.com`,
    `bulk-zone-c-${timestamp}.example.com`
  ];

  test.describe('Bulk Registration Page Access', () => {
    test('should access bulk registration page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      await expect(page).toHaveURL(/page=bulk_registration/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/bulk|registration|zones/i);
    });

    test('should display bulk input textarea', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      const textarea = page.locator('textarea[name="domains"]');
      await expect(textarea).toBeVisible();
    });

    test('should display owner selection', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      const ownerSelect = page.locator('select[name="owner"]');
      await expect(ownerSelect).toBeVisible();
    });

    test('should display zone type selection', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      const typeSelect = page.locator('select[name="dom_type"]');
      await expect(typeSelect).toBeVisible();
    });

    test('should display template selection', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      const templateSelect = page.locator('select[name="zone_template"]');
      await expect(templateSelect).toBeVisible();
    });
  });

  test.describe('Bulk Registration Form Submission', () => {
    test('should handle empty input gracefully', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should register single zone', async ({ adminPage: page }) => {
      const singleDomain = `bulk-single-${timestamp}.example.com`;
      await page.goto('/index.php?page=bulk_registration');

      await page.locator('textarea[name="domains"]').fill(singleDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify success or check zone list
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Clean up
      await page.goto('/index.php?page=list_forward_zones&letter=all');
      const row = page.locator(`tr:has-text("${singleDomain}")`);
      if (await row.count() > 0) {
        await row.locator('a[href*="delete_domain"]').first().click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should register multiple zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');

      await page.locator('textarea[name="domains"]').fill(testDomains.join('\n'));
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify success
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show created zones in list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_forward_zones&letter=all');

      // Click "Show all" to show all zones regardless of letter filter
      const showAllBtn = page.locator('a, button').filter({ hasText: 'Show all' });
      if (await showAllBtn.count() > 0) {
        await showAllBtn.first().click();
        await page.waitForLoadState('networkidle');
      }

      // Verify at least one test zone exists
      const bodyText = await page.locator('body').textContent();
      const hasTestZone = testDomains.some(domain => bodyText.includes(domain));
      expect(hasTestZone).toBeTruthy();
    });
  });

  test.describe('Bulk Registration - User Permissions', () => {
    test('admin should access bulk registration', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      // Verify form elements are visible (admin has access)
      const textarea = page.locator('textarea[name="domains"]');
      await expect(textarea).toBeVisible();
    });

    test('manager should access bulk registration', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not access bulk registration', async ({ viewerPage: page }) => {
      await page.goto('/index.php?page=bulk_registration');
      const bodyText = await page.locator('body').textContent();
      // Viewer should see permission denied or be redirected
      const hasNoAccess = bodyText.toLowerCase().includes('permission') ||
                          bodyText.toLowerCase().includes('denied') ||
                          !page.url().includes('bulk_registration');
      expect(hasNoAccess).toBeTruthy();
    });
  });

  // Cleanup test domains
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_forward_zones&letter=all');

    for (const domain of testDomains) {
      const row = page.locator(`tr:has-text("${domain}")`);
      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_domain"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) await yesBtn.click();
          await page.waitForTimeout(300);
        }
      }
    }

    await page.close();
  });
});
