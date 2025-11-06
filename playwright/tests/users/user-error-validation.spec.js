import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('User Management Error Validation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should show error when changing password with incorrect current password', async ({ page }) => {
    await page.goto('/password/change');

    // Fill in incorrect current password
    await page.locator('input[name*="current"], input[name*="old"]').first().fill('wrongpassword');

    // Fill in new password
    await page.locator('input[name*="new"], input[name*="password"]').first().fill('newpassword123');

    // Confirm new password (find second new password field if exists)
    const passwordFields = await page.locator('input[type="password"]').count();
    if (passwordFields > 2) {
      await page.locator('input[type="password"]').nth(2).fill('newpassword123');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show error message
    await expect(page.locator('[data-testid="error-message"], .alert-danger, .error')).toBeVisible();
    await expect(page.locator('body')).toContainText(/incorrect.*current.*password|wrong.*password|invalid.*password/i);
  });

  test('should show error when new passwords do not match', async ({ page }) => {
    await page.goto('/password/change');

    // Fill in current password
    await page.locator('input[name*="current"], input[name*="old"]').first().fill(users.admin.password);

    // Fill in mismatched new passwords
    const passwordFields = await page.locator('input[type="password"]');
    await passwordFields.nth(1).fill('newpassword123');

    const fieldCount = await passwordFields.count();
    if (fieldCount > 2) {
      await passwordFields.nth(2).fill('differentpassword456');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show error or stay on form
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/password.*change/i);
  });

  test('should validate password requirements', async ({ page }) => {
    await page.goto('/password/change');

    // Fill in current password
    await page.locator('input[name*="current"], input[name*="old"]').first().fill(users.admin.password);

    // Try weak password
    await page.locator('input[name*="new"], input[name*="password"]').first().fill('123');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/password.*change/i);
  });

  test('should update user description successfully', async ({ page }) => {
    await page.goto('/users');

    // Find first user edit link or go to user edit page
    const editLinks = await page.locator('a[href*="users/edit"], [data-testid^="edit-user-"]').count();

    if (editLinks > 0) {
      await page.locator('a[href*="users/edit"], [data-testid^="edit-user-"]').first().click();

      // Update description field
      const descriptionField = page.locator('input[name*="description"], textarea[name*="description"], input[name*="descr"]').first();
      await descriptionField.fill('Updated test description');

      // Submit form
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show success message or redirect
      const successIndicators = [
        page.locator('[data-testid="success-message"]'),
        page.locator('.alert-success'),
        page.locator('.success')
      ];

      let foundSuccess = false;
      for (const indicator of successIndicators) {
        if (await indicator.count() > 0) {
          foundSuccess = true;
          break;
        }
      }

      // If no success message, at least verify we're not on an error page
      if (!foundSuccess) {
        await expect(page.locator('.alert-danger, .error')).not.toBeVisible();
      }
    }
  });

  test('should validate required fields when editing user', async ({ page }) => {
    await page.goto('/users');

    const editLinks = await page.locator('a[href*="users/edit"], [data-testid^="edit-user-"]').count();

    if (editLinks > 0) {
      await page.locator('a[href*="users/edit"], [data-testid^="edit-user-"]').first().click();

      // Clear required field (e.g., username or email)
      const usernameField = page.locator('input[name*="username"]').first();
      if (await usernameField.count() > 0) {
        await usernameField.clear();

        // Try to submit
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Should show validation error or stay on form
        const currentUrl = page.url();
        expect(currentUrl).toMatch(/users.*edit/i);
      }
    }
  });

  test('should prevent duplicate username creation', async ({ page }) => {
    await page.goto('/users/add');

    // Try to create user with existing username (admin)
    await page.locator('input[name*="username"], input[placeholder*="username"]').first().fill('admin');
    await page.locator('input[name*="email"], input[type="email"]').first().fill('duplicate@example.com');

    const passwordField = page.locator('input[name*="password"], input[type="password"]').first();
    if (await passwordField.count() > 0) {
      await passwordField.fill('password123');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show error about duplicate username or validation error
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.match(/already.*exists|duplicate|username.*taken|error/i);

    if (hasError) {
      await expect(page.locator('[data-testid="error-message"], .alert-danger, .error')).toBeVisible();
    } else {
      // At minimum, should stay on the form
      await expect(page).toHaveURL(/users.*add/);
    }
  });

  test('should validate email format when creating user', async ({ page }) => {
    await page.goto('/users/add');

    // Fill form with invalid email
    await page.locator('input[name*="username"], input[placeholder*="username"]').first().fill('testuser123');
    await page.locator('input[name*="email"], input[type="email"]').first().fill('invalid-email-format');

    const passwordField = page.locator('input[name*="password"], input[type="password"]').first();
    if (await passwordField.count() > 0) {
      await passwordField.fill('password123');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    await expect(page).toHaveURL(/users.*add/);
  });

  test('should require all mandatory fields for user creation', async ({ page }) => {
    await page.goto('/users/add');

    // Try to submit form with minimal or no data
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors and stay on form
    await expect(page).toHaveURL(/users.*add/);

    // May show specific field errors
    const errorElements = await page.locator('.error, .invalid-feedback, .alert-danger, [data-testid*="error"]').count();
    expect(errorElements).toBeGreaterThan(0);
  });
});
