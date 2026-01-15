import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Password Management Tests
 *
 * These tests run serially to ensure proper password state between tests.
 * Tests that change the password must restore it via UI before completing.
 */
test.describe.serial('Password Management', () => {
  test('should access change password page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=change_password');
    await expect(page).toHaveURL(/page=change_password/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should display password form fields', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=change_password');

    const passwordFields = page.locator('input[type="password"]');
    // Should have at least current password and new password fields
    expect(await passwordFields.count()).toBeGreaterThanOrEqual(2);
  });

  test('should reject empty current password', async ({ adminPage: page }) => {
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

  test('should reject password confirmation mismatch', async ({ adminPage: page }) => {
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

  test('should reject wrong current password', async ({ adminPage: page }) => {
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
   * Test that verifies the password change form submission works correctly.
   * This test changes the password and then restores it to maintain test state.
   */
  test('should change password successfully and restore it', async ({ page }) => {
    const originalPassword = users.admin.password;
    const newPassword = 'NewAdmin456!';

    // Step 1: Log in with original password
    await page.goto('/index.php');
    await page.locator('input[name="username"]').fill(users.admin.username);
    await page.locator('input[name="password"]').fill(originalPassword);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await expect(page).toHaveURL(/index\.php/);

    // Step 2: Change password to new password
    await page.goto('/index.php?page=change_password');
    const passwordFields = page.locator('input[type="password"]');
    await passwordFields.nth(0).fill(originalPassword);
    await passwordFields.nth(1).fill(newPassword);
    await passwordFields.nth(2).fill(newPassword);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should be logged out after password change
    await expect(page).toHaveURL(/index\.php/);

    // Step 3: Log in with new password
    await page.locator('input[name="username"]').fill(users.admin.username);
    await page.locator('input[name="password"]').fill(newPassword);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await expect(page).toHaveURL(/index\.php/);

    // Verify we're logged in (should see dashboard content)
    const bodyText = await page.locator('body').textContent();
    expect(bodyText.toLowerCase()).toMatch(/welcome|dashboard|zones/i);

    // Step 4: Restore original password
    await page.goto('/index.php?page=change_password');
    const restoreFields = page.locator('input[type="password"]');
    await restoreFields.nth(0).fill(newPassword);
    await restoreFields.nth(1).fill(originalPassword);
    await restoreFields.nth(2).fill(originalPassword);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should be logged out again
    await expect(page).toHaveURL(/index\.php/);

    // Step 5: Verify we can log in with original password
    await page.locator('input[name="username"]').fill(users.admin.username);
    await page.locator('input[name="password"]').fill(originalPassword);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await expect(page).toHaveURL(/index\.php/);

    // Verify login successful
    const finalBodyText = await page.locator('body').textContent();
    expect(finalBodyText.toLowerCase()).toMatch(/welcome|dashboard|zones/i);
  });
});
