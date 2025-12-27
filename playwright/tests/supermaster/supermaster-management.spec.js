import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Supermaster Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access supermaster list from dashboard', async ({ page }) => {
    // Click on Supermasters card from dashboard
    await page.getByText('List supermasters').click();
    await expect(page).toHaveURL(/.*supermaster/);
  });

  test('should show supermaster list page', async ({ page }) => {
    await page.getByText('List supermasters').click();

    // Should show supermaster table or list
    await expect(page.locator('table, .table, [class*="supermaster"]')).toBeVisible();
  });

  test('should add a new supermaster', async ({ page }) => {
    // Navigate to add supermaster page
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('Add supermaster')) {
      await page.getByText('Add supermaster').click();
    } else {
      await page.getByText('List supermasters').click();
      await page.getByText('Add', { timeout: 5000 }).click();
    }

    // Fill in supermaster details
    await page.locator('input[name*="ip"], input[placeholder*="ip"]').fill('192.168.1.100');
    await page.locator('input[name*="nameserver"], input[placeholder*="nameserver"]').fill('ns1.example.com');
    await page.locator('input[name*="account"], input[placeholder*="account"]').fill('test-account');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Verify success
    await expect(page.locator('.alert, .message, [class*="success"]').first()).toBeVisible({ timeout: 10000 });
  });

  test('should list the created supermaster', async ({ page }) => {
    await page.getByText('List supermasters').click();

    // Should show the test supermaster we created
    await expect(page.getByText('192.168.1.100')).toBeVisible();
    await expect(page.getByText('ns1.example.com')).toBeVisible();
  });

  test('should edit a supermaster', async ({ page }) => {
    await page.getByText('List supermasters').click();

    // Find the test supermaster and edit it
    const row = page.locator('tr:has-text("192.168.1.100")');
    await row.locator('a, button').filter({ hasText: /Edit|Modify/i }).click();

    // Update supermaster details
    await page.locator('input[name*="nameserver"], input[placeholder*="nameserver"]').clear();
    await page.locator('input[name*="nameserver"], input[placeholder*="nameserver"]').fill('ns2.example.com');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Verify success
    await expect(page.locator('.alert, .message, [class*="success"]').first()).toBeVisible({ timeout: 10000 });
  });

  test('should delete a supermaster', async ({ page }) => {
    await page.getByText('List supermasters').click();

    // Find the test supermaster and delete it
    const row = page.locator('tr:has-text("192.168.1.100")');
    await row.locator('a, button').filter({ hasText: /Delete|Remove/i }).click();

    // Confirm deletion if needed
    const confirmText = await page.locator('body').textContent();
    if (confirmText.includes('confirm') || confirmText.includes('sure')) {
      await page.getByText('Yes').click();
    }

    // Verify deletion
    await expect(page.locator('.alert, .message, [class*="success"]').first()).toBeVisible({ timeout: 10000 });
  });

  test('should validate supermaster form', async ({ page }) => {
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('Add supermaster')) {
      await page.getByText('Add supermaster').click();
    } else {
      await page.getByText('List supermasters').click();
      await page.getByText('Add', { timeout: 5000 }).click();
    }

    // Submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show validation error
    const hasError = await page.locator('.error, .invalid, [class*="error"]').count();
    expect(hasError).toBeGreaterThan(0);
  });
});
