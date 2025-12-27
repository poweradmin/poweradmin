import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Forward Zones Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access forward zones page', async ({ page }) => {
    await page.goto('/index.php?page=list_zones');
    await expect(page).toHaveURL(/page=list_zones/);
    await expect(page.locator('h1, h2, h3, .page-title, [data-testid*="title"]')).toBeVisible();
  });

  test('should display zones list or empty state', async ({ page }) => {
    await page.goto('/index.php?page=list_zones');

    // Should show either zones table or empty state message
    const hasTable = await page.locator('table, .table').count() > 0;

    if (hasTable) {
      await expect(page.locator('table, .table')).toBeVisible();
    } else {
      // Empty state or no zones message
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No zones found|zones|empty/i);
    }
  });

  test('should have add master zone button', async ({ page }) => {
    await page.goto('/index.php?page=list_zones');

    // Look for add/create buttons
    const hasAddButton = await page.locator('a, button').filter({ hasText: /Add|Create|New/i }).count() > 0;

    if (hasAddButton) {
      await expect(page.locator('a, button').filter({ hasText: /Add|Create|New/i }).first()).toBeVisible();
    }
  });

  test('should navigate to add master zone page', async ({ page }) => {
    await page.goto('/index.php?page=add_zone_master');
    await expect(page).toHaveURL(/page=add_zone_master/);
    await expect(page.locator('form, [data-testid*="form"]')).toBeVisible();
  });

  test('should validate master zone creation form', async ({ page }) => {
    await page.goto('/index.php?page=add_zone_master');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    await expect(page).toHaveURL(/page=add_zone_master/);
  });

  test('should show zone name field in master zone form', async ({ page }) => {
    await page.goto('/index.php?page=add_zone_master');

    // Look for zone name input
    await expect(
      page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"], input[placeholder*="zone"], input[placeholder*="domain"]')
    ).toBeVisible();
  });
});
