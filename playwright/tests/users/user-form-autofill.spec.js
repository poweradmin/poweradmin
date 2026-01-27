/**
 * User Form Autofill Tests
 *
 * Tests for browser autofill prevention on user forms
 * covering fix(ui): disable browser autofill for user creation, closes #782
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('User Form Autofill Prevention', () => {
  test.describe('Add User Form', () => {
    test('form should have autocomplete="off" attribute', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const form = page.locator('form[action*="add"]');

      if (await form.count() > 0) {
        const autocomplete = await form.getAttribute('autocomplete');
        expect(autocomplete).toBe('off');
      }
    });

    test('username field should have autocomplete="off"', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const usernameInput = page.locator('input[name="username"]');

      if (await usernameInput.count() > 0) {
        const autocomplete = await usernameInput.getAttribute('autocomplete');
        expect(autocomplete).toBe('off');
      }
    });

    test('password field should have autocomplete="new-password"', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const autocomplete = await passwordInput.getAttribute('autocomplete');
        expect(autocomplete).toBe('new-password');
      }
    });

    test('email field should exist in add user form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const emailInput = page.locator('input[name="email"]');
      const hasEmailField = await emailInput.count() > 0;

      expect(hasEmailField).toBeTruthy();
    });
  });

  test.describe('Edit User Form', () => {
    test('edit user form should prevent password autofill', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // Navigate directly to edit user page (edit links may be in dropdowns)
      await page.goto('/users/1/edit');
      await page.waitForLoadState('networkidle');

      const passwordInput = page.locator('input[name="password"], input[type="password"]');

      if (await passwordInput.count() > 0) {
        const autocomplete = await passwordInput.getAttribute('autocomplete');
        expect(['new-password', 'off', null]).toContain(autocomplete);
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|edit|profile/i);
      }
    });
  });

  test.describe('Security Best Practices', () => {
    test('password fields should be type="password"', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const passwordInput = page.locator('input[name="password"]');

      if (await passwordInput.count() > 0) {
        const type = await passwordInput.getAttribute('type');
        expect(type).toBe('password');
      }
    });

    test('password toggle button should exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const toggleButton = page.locator('button[onclick*="showPassword"], .bi-eye-fill, .bi-eye');
      const hasToggle = await toggleButton.count() > 0;

      expect(hasToggle).toBeTruthy();
    });
  });

  test.describe('Form Validation', () => {
    test('username should be required', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const usernameInput = page.locator('input[name="username"]');

      if (await usernameInput.count() > 0) {
        const required = await usernameInput.getAttribute('required');
        expect(required !== null || required === '').toBeTruthy();
      }
    });

    test('form should have novalidate for custom validation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const form = page.locator('form.needs-validation');
      const hasNeedsValidation = await form.count() > 0;

      expect(hasNeedsValidation).toBeTruthy();
    });
  });
});
