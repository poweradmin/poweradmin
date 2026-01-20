import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Master Zone Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should add a master zone successfully', async ({ page }) => {
    // Navigate directly to add zone page
    await page.goto('/zones/add/master');

    // Fill in zone name using data-testid
    await page.locator('[data-testid="zone-name-input"]').fill('test-master.example.com');

    // Submit the form and wait for navigation
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify success - should redirect to forward zones or list page
    // Allow either /zones/forward or staying on form with success (different implementations)
    const url = page.url();
    if (url.includes('/zones/forward') || url.includes('/zones/list')) {
      // Redirected to zone list - success
      await expect(page).toHaveURL(/zones\/(forward|list)/);
    } else {
      // May stay on form with success message in some implementations
      await expect(page.locator('[data-testid="system-message"], .alert-success').first()).toBeVisible();
    }
  });

  test('should add a reverse zone successfully', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill('1.168.192.in-addr.arpa');
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Check for success - redirect or success message
    const url = page.url();
    if (url.includes('/zones/reverse') || url.includes('/zones/list')) {
      await expect(page).toHaveURL(/zones\/(reverse|list)/);
    } else {
      await expect(page.locator('[data-testid="system-message"], .alert-success').first()).toBeVisible();
    }
  });

  test('should add a record to a master zone successfully', async ({ page }) => {
    await page.locator('[data-testid="list-zones-link"]').click();

    await page.locator('tr').filter({ hasText: 'test-master.example.com' }).locator('[data-testid^="edit-zone-"]').click();

    await page.locator('[data-testid="record-name-input"]').fill('www');
    await page.locator('[data-testid="record-content-input"]').fill('192.168.1.1');
    // Only check the reverse record checkbox if it exists and is unchecked
    const reverseCheckbox = page.locator('[data-testid="add-reverse-record-checkbox"]');
    if (await reverseCheckbox.count() > 0 && !(await reverseCheckbox.isChecked())) {
      await reverseCheckbox.check();
    }
    await page.locator('[data-testid="add-record-button"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('The record was successfully added.');
  });

  test('should delete a master zone successfully', async ({ page }) => {
    await page.locator('[data-testid="list-zones-link"]').click();

    await page.locator('tr').filter({ hasText: 'test-master.example.com' }).locator('[data-testid^="delete-zone-"]').click();

    await page.locator('[data-testid="confirm-delete-zone"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('Zone has been deleted successfully.');
  });

  test('should delete a reverse zone successfully', async ({ page }) => {
    await page.locator('[data-testid="list-zones-link"]').click();

    await page.locator('tr').filter({ hasText: '1.168.192.in-addr.arpa' }).locator('[data-testid^="delete-zone-"]').click();

    await page.locator('[data-testid="confirm-delete-zone"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('Zone has been deleted successfully.');
  });
});
