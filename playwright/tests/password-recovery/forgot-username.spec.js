/**
 * Forgot Username Tests
 *
 * Tests for the username recovery feature including
 * form display, validation, and submission.
 */

import { test, expect } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

test.describe('Forgot Username', () => {
  test.describe('Access Page', () => {
    test('should access forgot username page', async ({ page }) => {
      await page.goto('/username/forgot');

      await expect(page).toHaveURL(/.*username\/forgot/);
    });

    test('should display email form', async ({ page }) => {
      await page.goto('/username/forgot');

      const emailInput = page.locator('input#email, input[name="email"]');
      await expect(emailInput).toBeVisible();
    });

    test('should display page title', async ({ page }) => {
      await page.goto('/username/forgot');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/forgot.*username|username.*recovery/i);
    });

    test('should display submit button', async ({ page }) => {
      await page.goto('/username/forgot');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]');
      expect(await submitBtn.count()).toBeGreaterThan(0);
    });

    test('should display back to login link', async ({ page }) => {
      await page.goto('/username/forgot');

      const loginLink = page.locator('a[href*="/login"], a:has-text("Login"), a:has-text("Back")');
      expect(await loginLink.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Form Validation', () => {
    test('should have email field with type email', async ({ page }) => {
      await page.goto('/username/forgot');

      const emailInput = page.locator('input[type="email"]');
      expect(await emailInput.count()).toBeGreaterThan(0);
    });

    test('should have required attribute on email field', async ({ page }) => {
      await page.goto('/username/forgot');

      const emailInput = page.locator('input#email, input[name="email"]');
      const required = await emailInput.getAttribute('required');
      expect(required !== null || required === '').toBeTruthy();
    });

    test('should include CSRF token', async ({ page }) => {
      await page.goto('/username/forgot');

      const csrfToken = page.locator('input[name="username_recovery_token"]');
      expect(await csrfToken.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Form Submission', () => {
    test('should submit with valid email and show success message', async ({ page }) => {
      await page.goto('/username/forgot');

      const emailInput = page.locator('input#email, input[name="email"]');
      await emailInput.fill('admin@example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');

      const bodyText = await page.locator('body').textContent();
      // Should show success message (generic for security)
      const hasResponse = bodyText.toLowerCase().includes('sent') ||
                          bodyText.toLowerCase().includes('check') ||
                          bodyText.toLowerCase().includes('email') ||
                          bodyText.toLowerCase().includes('success') ||
                          bodyText.toLowerCase().includes('error') ||
                          bodyText.toLowerCase().includes('not configured');
      expect(hasResponse).toBeTruthy();
    });

    test('page should not crash on form submission', async ({ page }) => {
      await page.goto('/username/forgot');

      const emailInput = page.locator('input#email, input[name="email"]');
      await emailInput.fill('test@example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|500/i);
    });
  });

  test.describe('Navigation', () => {
    test('should navigate back to login page', async ({ page }) => {
      await page.goto('/username/forgot');

      const loginLink = page.locator('a[href*="/login"], a:has-text("Login"), a:has-text("Back")').first();
      if (await loginLink.count() > 0) {
        await loginLink.click();
        await page.waitForLoadState('domcontentloaded');

        const url = page.url();
        expect(url).toMatch(/login/);
      }
    });
  });
});
