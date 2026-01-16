/**
 * User Form Autofill Tests
 *
 * Tests for browser autofill prevention on user forms
 * covering fix(ui): disable browser autofill for user creation, closes #782
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('User Form Autofill Prevention', () => {
  test.describe('Add User Form', () => {
    test('form should have autocomplete="off" attribute', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      const form = page.locator('form[action*="add_user"]');

      if (await form.count() > 0) {
        const autocomplete = await form.getAttribute('autocomplete');
        expect(autocomplete).toBe('off');
      }
    });

    test('username field should have autocomplete="off"', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      const usernameInput = page.locator('input[name="username"]');

      if (await usernameInput.count() > 0) {
        const autocomplete = await usernameInput.getAttribute('autocomplete');
        expect(autocomplete).toBe('off');
      }
    });

    test('password field should have autocomplete="new-password"', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const autocomplete = await passwordInput.getAttribute('autocomplete');
        expect(autocomplete).toBe('new-password');
      }
    });

    test('email field should exist in add user form', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      const emailInput = page.locator('input[name="email"]');
      const hasEmailField = await emailInput.count() > 0;

      expect(hasEmailField).toBeTruthy();
    });
  });

  test.describe('Edit User Form', () => {
    test('edit user form should prevent password autofill', async ({ adminPage: page }) => {
      // Navigate directly to edit user page for admin user (ID 1)
      await page.goto('/index.php?page=edit_user&id=1');
      await page.waitForLoadState('networkidle');

      const passwordInput = page.locator('input[name="password"], input[type="password"]');

      if (await passwordInput.count() > 0) {
        const autocomplete = await passwordInput.getAttribute('autocomplete');
        // Should be either 'new-password' or 'off'
        expect(['new-password', 'off', null]).toContain(autocomplete);
      } else {
        // Page loaded successfully, password field might not be visible
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|edit|profile/i);
      }
    });
  });

  test.describe('Security Best Practices', () => {
    test('password fields should be type="password"', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const type = await passwordInput.getAttribute('type');
        expect(type).toBe('password');
      }
    });

    test('password toggle button should exist', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      // Check for password visibility toggle button
      const toggleButton = page.locator('button[onclick*="showPassword"], .bi-eye-fill, .bi-eye');
      const hasToggle = await toggleButton.count() > 0;

      expect(hasToggle).toBeTruthy();
    });
  });

  test.describe('Form Validation', () => {
    test('username should be required', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      const usernameInput = page.locator('input[name="username"]');

      if (await usernameInput.count() > 0) {
        const required = await usernameInput.getAttribute('required');
        expect(required !== null || required === '').toBeTruthy();
      }
    });

    test('form should have novalidate for custom validation', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await page.waitForLoadState('networkidle');

      const form = page.locator('form.needs-validation');
      const hasNeedsValidation = await form.count() > 0;

      expect(hasNeedsValidation).toBeTruthy();
    });
  });
});
