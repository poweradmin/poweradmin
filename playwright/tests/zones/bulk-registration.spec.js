/**
 * Bulk Zone Registration Tests
 *
 * Tests for bulk zone registration functionality including
 * registering multiple zones at once and automatic record generation.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Bulk Zone Registration', () => {
  const timestamp = Date.now();
  const testDomains = [
    `bulk-zone-a-${timestamp}.example.com`,
    `bulk-zone-b-${timestamp}.example.com`,
    `bulk-zone-c-${timestamp}.example.com`
  ];

  test.describe('Bulk Registration Page Access', () => {
    test('should access bulk registration page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');
      await expect(page).toHaveURL(/.*zones\/bulk-registration/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/bulk|registration|zones/i);
    });

    test('should display bulk input textarea', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');
      const textarea = page.locator('textarea[name="domains"]');
      await expect(textarea).toBeVisible();
    });

    test('should display owner selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');
      const ownerSelect = page.locator('select[name="owner"]');
      await expect(ownerSelect).toBeVisible();
    });

    test('should display zone type selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');
      const typeSelect = page.locator('select[name="dom_type"]');
      await expect(typeSelect).toBeVisible();
    });

    test('should display template selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');
      const templateSelect = page.locator('select[name="zone_template"]');
      await expect(templateSelect).toBeVisible();
    });
  });

  test.describe('Bulk Registration Form Submission', () => {
    test('should handle empty input gracefully', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should register single zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const singleDomain = `bulk-single-${timestamp}.example.com`;
      await page.goto('/zones/bulk-registration');

      await page.locator('textarea[name="domains"]').fill(singleDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify success or check zone list
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Clean up
      await page.goto('/zones/forward?letter=all');
      const row = page.locator(`tr:has-text("${singleDomain}")`);
      if (await row.count() > 0) {
        await row.locator('a[href*="/delete"]').first().click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should register multiple zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');

      await page.locator('textarea[name="domains"]').fill(testDomains.join('\n'));
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify success
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show created zones in list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      // Verify at least one test zone exists
      const bodyText = await page.locator('body').textContent();
      const hasTestZone = testDomains.some(domain => bodyText.includes(domain));
      expect(hasTestZone).toBeTruthy();
    });
  });

  test.describe('Bulk Registration - User Permissions', () => {
    test('admin should access bulk registration', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/bulk-registration');
      // Verify form elements are visible (admin has access)
      const textarea = page.locator('textarea[name="domains"]');
      await expect(textarea).toBeVisible();
    });

    test('manager should access bulk registration', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/bulk-registration');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not access bulk registration', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/bulk-registration');
      const bodyText = await page.locator('body').textContent();
      // Viewer should see permission denied or be redirected
      const hasNoAccess = bodyText.toLowerCase().includes('permission') ||
                          bodyText.toLowerCase().includes('denied') ||
                          !page.url().includes('bulk-registration');
      expect(hasNoAccess).toBeTruthy();
    });
  });

  // Cleanup test domains
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/zones/forward?letter=all');

    for (const domain of testDomains) {
      const row = page.locator(`tr:has-text("${domain}")`);
      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="/delete"]').first();
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
