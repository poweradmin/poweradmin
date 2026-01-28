/**
 * Search Edge Cases Tests
 *
 * Tests for search functionality edge cases including:
 * - Clearing search field (regression test for #815)
 * - Special characters in search
 * - Empty search handling
 * - Search field interactions
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Search Edge Cases', () => {
  test.describe('Clear Search Field (Regression #815)', () => {
    test('should not crash when clearing search field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');

      // Type something
      await queryInput.fill('example');

      // Clear the field
      await queryInput.clear();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|crash/i);
    });

    test('should handle rapid clear and type cycles', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');

      // Rapid cycles of type and clear
      for (let i = 0; i < 5; i++) {
        await queryInput.fill(`test${i}`);
        await queryInput.clear();
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|crash/i);
    });

    test('should handle clear via keyboard (Ctrl+A, Delete)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('example.com');

      // Clear via keyboard
      await queryInput.focus();
      await page.keyboard.press('Control+a');
      await page.keyboard.press('Delete');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|crash/i);
    });

    test('should handle clear via backspace', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('test');

      // Clear via backspace
      await queryInput.focus();
      for (let i = 0; i < 10; i++) {
        await page.keyboard.press('Backspace');
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception|crash/i);
    });
  });

  test.describe('Empty Search Handling', () => {
    test('should handle empty search submission', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();

      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle whitespace-only search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('   ');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();

      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle search after clearing results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');

      // First search
      await queryInput.fill('*');
      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Clear and search again
      await queryInput.clear();
      await queryInput.fill('example');
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Special Characters in Search', () => {
    test('should handle asterisk wildcard', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('*');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle percent wildcard', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('%');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle underscore character', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('test_zone');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle SQL injection attempt', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill("'; DROP TABLE domains; --");

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle XSS attempt in search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('<script>alert("xss")</script>');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      // Should not execute script
      expect(bodyText).not.toContain('<script>');
    });

    test('should handle unicode characters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('域名.中国');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle very long search query', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('a'.repeat(500));

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Search Options Combinations', () => {
    test('should handle all checkboxes unchecked', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      // Uncheck all options
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      for (let i = 0; i < count; i++) {
        await checkboxes.nth(i).uncheck().catch(() => {});
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('test');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle all checkboxes checked', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      // Check all options
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      for (let i = 0; i < count; i++) {
        await checkboxes.nth(i).check().catch(() => {});
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('*');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Search Results Interaction', () => {
    test('should handle clicking search result link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('*');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Click on first result link if available
      const resultLink = page.locator('table a, .search-results a').first();
      if (await resultLink.count() > 0) {
        await resultLink.click();
        await page.waitForLoadState('networkidle');

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should handle browser back after search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('example');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Navigate away
      await page.goto('/zones/forward?letter=all');

      // Go back
      await page.goBack();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
