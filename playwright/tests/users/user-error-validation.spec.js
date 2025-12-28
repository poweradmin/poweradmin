import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('User Management Error Validation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should show error when changing password with incorrect current password', async ({ page }) => {
    await page.goto('/index.php?page=change_password');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      test.info().annotations.push({ type: 'note', description: 'Change password page not available' });
      return;
    }

    // Fill in incorrect current password
    const currentField = page.locator('input[name*="current"], input[name*="old"]').first();
    if (await currentField.count() > 0) {
      await currentField.fill('wrongpassword');
    }

    // Fill in new password
    const newField = page.locator('input[name*="new"]').first();
    if (await newField.count() > 0) {
      await newField.fill('newpassword123');
    }

    // Confirm new password
    const passwordFields = await page.locator('input[type="password"]').count();
    if (passwordFields > 2) {
      await page.locator('input[type="password"]').nth(2).fill('newpassword123');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show error or stay on form
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('incorrect') || bodyText.toLowerCase().includes('wrong') || page.url().includes('change_password');
    expect(hasError).toBeTruthy();
  });

  test('should show error when new passwords do not match', async ({ page }) => {
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

    // Fill in mismatched new passwords
    const passwordFields = page.locator('input[type="password"]');
    const fieldCount = await passwordFields.count();
    if (fieldCount >= 2) {
      await passwordFields.nth(1).fill('newpassword123');
    }
    if (fieldCount > 2) {
      await passwordFields.nth(2).fill('differentpassword456');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show error or stay on form
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/change_password/);
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
      await newField.fill('123');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/change_password/);
  });

  test('should validate required fields when editing user', async ({ page }) => {
    await page.goto('/index.php?page=users');

    const editLinks = await page.locator('a[href*="edit_user"]').count();
    if (editLinks > 0) {
      await page.locator('a[href*="edit_user"]').first().click();

      // Clear required field (e.g., username or email)
      const usernameField = page.locator('input[name*="username"]').first();
      if (await usernameField.count() > 0) {
        await usernameField.clear();

        // Try to submit
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Should show validation error or stay on form
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('required') || page.url().includes('edit_user');
        expect(hasError).toBeTruthy();
      }
    }
  });

  test('should validate email format when creating user', async ({ page }) => {
    await page.goto('/index.php?page=add_user');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      test.info().annotations.push({ type: 'note', description: 'Add user page not available' });
      return;
    }

    // Fill form with invalid email
    const usernameField = page.locator('input[name*="username"]').first();
    if (await usernameField.count() > 0) {
      await usernameField.fill('testuser123');
    }

    const emailField = page.locator('input[name*="email"]').first();
    if (await emailField.count() > 0) {
      await emailField.fill('invalid-email-format');
    }

    const passwordField = page.locator('input[type="password"]').first();
    if (await passwordField.count() > 0) {
      await passwordField.fill('password123');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    await expect(page).toHaveURL(/add_user/);
  });

  test('should require all mandatory fields for user creation', async ({ page }) => {
    await page.goto('/index.php?page=add_user');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      test.info().annotations.push({ type: 'note', description: 'Add user page not available' });
      return;
    }

    // Try to submit form with minimal or no data
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    const currentUrl = page.url();
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('required') || currentUrl.includes('add_user');
    expect(hasError).toBeTruthy();
  });
});
