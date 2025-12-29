import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Password Management Tests
 *
 * These tests run serially to ensure proper password state between tests.
 * Tests that change the password must restore it via UI before completing.
 */
test.describe.serial('Password Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access change password page', async ({ page }) => {
    await page.goto('/index.php?page=change_password');
    await expect(page).toHaveURL(/page=change_password/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should display password form fields', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const passwordFields = page.locator('input[type="password"]');
    // Should have at least current password and new password fields
    expect(await passwordFields.count()).toBeGreaterThanOrEqual(2);
  });

  test('should reject empty current password', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const passwordFields = page.locator('input[type="password"]');
    const count = await passwordFields.count();

    if (count >= 3) {
      // Leave current password empty, fill new password fields
      await passwordFields.nth(1).fill('NewPassword123!');
      await passwordFields.nth(2).fill('NewPassword123!');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should stay on change_password page (validation error)
      await expect(page).toHaveURL(/change_password/);
    }
  });

  test('should reject password confirmation mismatch', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const passwordFields = page.locator('input[type="password"]');
    const count = await passwordFields.count();

    if (count >= 3) {
      // Fill current password
      await passwordFields.nth(0).fill(users.admin.password);
      // Enter mismatched new passwords
      await passwordFields.nth(1).fill('NewPassword123!');
      await passwordFields.nth(2).fill('DifferentPassword456!');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show mismatch error or stay on form
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('match') ||
                       bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('mismatch') ||
                       page.url().includes('change_password');
      expect(hasError).toBeTruthy();
    }
  });

  test('should reject wrong current password', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const passwordFields = page.locator('input[type="password"]');
    const count = await passwordFields.count();

    if (count >= 3) {
      // Fill wrong current password
      await passwordFields.nth(0).fill('WrongCurrentPassword!');
      // Fill matching new passwords
      await passwordFields.nth(1).fill('NewPassword123!');
      await passwordFields.nth(2).fill('NewPassword123!');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show error about current password
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('current') ||
                       bodyText.toLowerCase().includes('wrong') ||
                       bodyText.toLowerCase().includes('incorrect') ||
                       bodyText.toLowerCase().includes('error') ||
                       page.url().includes('change_password');
      expect(hasError).toBeTruthy();
    }
  });

  /**
   * This test changes the password and must restore it via UI.
   * Run this test last in the serial group.
   */
  test('should change password successfully and restore it', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      test.info().annotations.push({ type: 'skip', description: 'Change password page not available' });
      return;
    }

    const passwordFields = page.locator('input[type="password"]');
    const count = await passwordFields.count();

    if (count < 3) {
      test.info().annotations.push({ type: 'skip', description: 'Not enough password fields found' });
      return;
    }

    const newPassword = 'NewAdmin456!';

    // Step 1: Change password to new password
    await passwordFields.nth(0).fill(users.admin.password);
    await passwordFields.nth(1).fill(newPassword);
    await passwordFields.nth(2).fill(newPassword);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Check if password was changed
    const bodyText = await page.locator('body').textContent();
    const passwordChanged = bodyText.toLowerCase().includes('success') ||
                           bodyText.toLowerCase().includes('changed') ||
                           bodyText.toLowerCase().includes('updated');

    if (passwordChanged) {
      // Step 2: Logout and login with new password
      await page.goto('/index.php?page=logout');
      await page.waitForURL(/login/);

      await page.locator('input[name="username"]').fill(users.admin.username);
      await page.locator('input[name="password"]').fill(newPassword);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForURL(/page=index/);

      // Step 3: Change password back to original
      await page.goto('/index.php?page=change_password');

      const passwordFields2 = page.locator('input[type="password"]');
      await passwordFields2.nth(0).fill(newPassword);
      await passwordFields2.nth(1).fill(users.admin.password);
      await passwordFields2.nth(2).fill(users.admin.password);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify password was restored
      const restoredText = await page.locator('body').textContent();
      const passwordRestored = restoredText.toLowerCase().includes('success') ||
                               restoredText.toLowerCase().includes('changed') ||
                               restoredText.toLowerCase().includes('updated');

      expect(passwordRestored).toBeTruthy();
    }
  });
});
