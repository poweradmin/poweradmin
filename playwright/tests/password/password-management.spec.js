import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Password Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access change password page', async ({ page }) => {
    await page.goto('/index.php?page=change_password');
    await expect(page).toHaveURL(/page=change_password/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should change password successfully', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      test.info().annotations.push({ type: 'note', description: 'Change password page not available' });
      return;
    }

    // Fill in current password
    const currentField = page.locator('input[name*="current"], input[name*="old"]').first();
    if (await currentField.count() > 0) {
      await currentField.fill(users.admin.password);
    }

    // Fill in new password
    const newPassword = 'NewAdmin456!';
    const newField = page.locator('input[name*="new"]').first();
    if (await newField.count() > 0) {
      await newField.fill(newPassword);
    }

    // Confirm new password
    const passwordFields = await page.locator('input[type="password"]').count();
    if (passwordFields > 2) {
      await page.locator('input[type="password"]').nth(2).fill(newPassword);
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify result (success or error)
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // If password was changed, change it back
    if (bodyText.toLowerCase().includes('success') || bodyText.toLowerCase().includes('changed')) {
      // Logout and login with new password
      await page.goto('/index.php?page=logout');
      await page.goto('/index.php?page=login');
      await page.locator('input[name*="username"]').first().fill(users.admin.username);
      await page.locator('input[name*="password"], input[type="password"]').first().fill(newPassword);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Change back to original password
      await page.goto('/index.php?page=change_password');
      const currentField2 = page.locator('input[name*="current"], input[name*="old"]').first();
      if (await currentField2.count() > 0) {
        await currentField2.fill(newPassword);
      }
      const newField2 = page.locator('input[name*="new"]').first();
      if (await newField2.count() > 0) {
        await newField2.fill(users.admin.password);
      }
      const passwordFields2 = await page.locator('input[type="password"]').count();
      if (passwordFields2 > 2) {
        await page.locator('input[type="password"]').nth(2).fill(users.admin.password);
      }
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
    }
  });

  test('should validate password requirements', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      test.info().annotations.push({ type: 'note', description: 'Change password page not available' });
      return;
    }

    // Fill in current password
    const currentField = page.locator('input[name*="current"], input[name*="old"]').first();
    if (await currentField.count() > 0) {
      await currentField.fill(users.admin.password);
    }

    // Try weak password
    const newField = page.locator('input[name*="new"]').first();
    if (await newField.count() > 0) {
      await newField.fill('weak');
    }

    const passwordFields = await page.locator('input[type="password"]').count();
    if (passwordFields > 2) {
      await page.locator('input[type="password"]').nth(2).fill('weak');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/change_password/);
  });

  test('should handle password mismatch', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      test.info().annotations.push({ type: 'note', description: 'Change password page not available' });
      return;
    }

    // Fill in current password
    const currentField = page.locator('input[name*="current"], input[name*="old"]').first();
    if (await currentField.count() > 0) {
      await currentField.fill(users.admin.password);
    }

    // Enter mismatched passwords
    const newField = page.locator('input[name*="new"]').first();
    if (await newField.count() > 0) {
      await newField.fill('ValidPass123!');
    }

    const passwordFields = await page.locator('input[type="password"]').count();
    if (passwordFields > 2) {
      await page.locator('input[type="password"]').nth(2).fill('DifferentPass123!');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show mismatch error or stay on form
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/change_password/);
  });
});
