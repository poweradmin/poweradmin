import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Password Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access change password page', async ({ page }) => {
    // Look for Account dropdown or direct link to change password
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('Account')) {
      await page.getByText('Account').click();
      await page.getByText('Change Password', { timeout: 5000 }).click();
    } else {
      // Direct navigation to change password route
      await page.goto('/password/change');
    }

    await expect(page).toHaveURL(/.*password\/change/);
  });

  test('should change password successfully', async ({ page }) => {
    await page.goto('/password/change');

    // Fill in current password
    await page.locator('input[name*="current"], input[placeholder*="current"]').fill(users.admin.password);

    // Fill in new password
    const newPassword = 'NewAdmin456!';
    await page.locator('input[name*="new"], input[name*="password"]').first().fill(newPassword);

    // Confirm new password
    await page.locator('input[name*="confirm"], input[name*="password"]').last().fill(newPassword);

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Verify success message
    await expect(page.locator('.alert, .message, [class*="success"]').first()).toBeVisible({ timeout: 10000 });

    // Test login with new password
    await page.goto('/logout');
    await page.goto('/login');
    await page.locator('input[name*="username"]').fill(users.admin.username);
    await page.locator('input[name*="password"]').fill(newPassword);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await expect(page).toHaveURL('/');

    // Change back to original password for other tests
    await page.goto('/password/change');
    await page.locator('input[name*="current"], input[placeholder*="current"]').fill(newPassword);
    await page.locator('input[name*="new"], input[name*="password"]').first().fill(users.admin.password);
    await page.locator('input[name*="confirm"], input[name*="password"]').last().fill(users.admin.password);
    await page.locator('button[type="submit"], input[type="submit"]').click();
  });

  test('should validate password requirements', async ({ page }) => {
    await page.goto('/password/change');

    // Fill in current password
    await page.locator('input[name*="current"], input[placeholder*="current"]').fill(users.admin.password);

    // Try weak password
    await page.locator('input[name*="new"], input[name*="password"]').first().fill('weak');
    await page.locator('input[name*="confirm"], input[name*="password"]').last().fill('weak');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show validation error
    await expect(page.locator('.error, .invalid, [class*="error"]').first()).toBeVisible({ timeout: 5000 });
  });

  test('should handle password mismatch', async ({ page }) => {
    await page.goto('/password/change');

    // Fill in current password
    await page.locator('input[name*="current"], input[placeholder*="current"]').fill(users.admin.password);

    // Enter mismatched passwords
    await page.locator('input[name*="new"], input[name*="password"]').first().fill('ValidPass123!');
    await page.locator('input[name*="confirm"], input[name*="password"]').last().fill('DifferentPass123!');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show mismatch error
    await expect(page.locator('.error, .invalid, [class*="error"]').first()).toBeVisible({ timeout: 5000 });
  });
});
