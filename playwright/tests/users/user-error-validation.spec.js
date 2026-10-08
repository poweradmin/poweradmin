import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('User Management Error Validation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should show error when changing password with incorrect current password', async ({ page }) => {
    await page.goto('/password/change');

    // A wrong current password is refused, so admin's own password is never changed
    await page.locator('input[name="old_password"]').fill('wrongpassword');
    await page.locator('input[name="new_password"]').fill('NewPassword123!');
    await page.locator('input[name="new_password2"]').fill('NewPassword123!');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    await expect(page.locator('[data-testid="system-message"]'))
      .toContainText(/did not enter the correct current password/i);
    await expect(page).toHaveURL(/\/password\/change/);
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

    const editLink = page.locator('table a[href*="/users/"][href$="/edit"]').first();
    await expect(editLink).toBeVisible();
    await editLink.click();

    // Update description field
    const descriptionField = page.locator('input[name*="description"], textarea[name*="description"], input[name*="descr"]').first();
    await descriptionField.fill('Updated test description');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    await expect(page.locator('[data-testid="system-message"]')).toContainText(/updated successfully/i);
  });

  test('should validate required fields when editing user', async ({ page }) => {
    await page.goto('/users');

    // Edit URLs are /users/{id}/edit, so a href*="users/edit" substring never matches
    const editLink = page.locator('table a[href*="/users/"][href$="/edit"]').first();
    await expect(editLink).toBeVisible();
    await editLink.click();

    const usernameField = page.locator('input[name*="username"]').first();
    await expect(usernameField).toBeVisible();
    await usernameField.clear();

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // A blank username must be rejected, leaving us on the edit form
    await expect(page).toHaveURL(/users.*edit/i);
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

    // The duplicate must be rejected: exactly one admin account may exist afterwards.
    // Match the username input itself - every row carries a permission-template
    // dropdown listing "Administrator", so a row-level text match hits all of them.
    await page.goto('/users');
    await expect(page.locator('input[name$="[username]"][value="admin"]')).toHaveCount(1);
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
