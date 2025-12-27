import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Master Zone Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should add a master zone successfully', async ({ page }) => {
    // First try clicking the Master Zone card from dashboard
    const bodyText = await page.locator('body').textContent();

    if (bodyText?.includes('Master Zone')) {
      // Click on Master Zone card from dashboard
      await page.getByText('Master Zone').click();
    } else {
      // Fallback: use navigation dropdown
      await page.getByText('Zones').click();
      await page.getByText('Add master zone').click();
    }

    // Fill in zone name
    await page.locator('input[name*="zone"], input[placeholder*="zone"], input[name*="name"]')
      .fill('example.com');

    // Submit the form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Verify success
    await expect(page.locator('.alert, .message, [class*="success"]')).toBeVisible({ timeout: 10000 });
  });

  test('should add a reverse zone successfully', async ({ page }) => {
    await page.locator('[data-testid="add-master-zone-link"]').click();
    await page.locator('[data-testid="zone-name-input"]').fill('1.168.192.in-addr.arpa');
    await page.locator('[data-testid="add-zone-button"]').click();

    await expect(page).toHaveURL(/.*zones\/reverse/);
    await expect(page.locator('[data-testid="alert-message"]')).toContainText('Zone has been added successfully.');
  });

  test('should add a record to a master zone successfully', async ({ page }) => {
    await page.locator('[data-testid="list-zones-link"]').click();

    await page.locator('tr').filter({ hasText: 'example.com' }).locator('[data-testid^="edit-zone-"]').click();

    await page.locator('[data-testid="record-name-input"]').fill('www');
    await page.locator('[data-testid="record-content-input"]').fill('192.168.1.1');
    await page.locator('[data-testid="add-reverse-record-checkbox"]').check();
    await page.locator('[data-testid="add-record-button"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('The record was successfully added.');
  });

  test('should delete a master zone successfully', async ({ page }) => {
    await page.locator('[data-testid="list-zones-link"]').click();

    await page.locator('tr').filter({ hasText: 'example.com' }).locator('[data-testid^="delete-zone-"]').click();

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
