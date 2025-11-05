import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Error Handling and Edge Cases', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test.describe('Session Management', () => {
    test('should handle forced logout and session expiration', async ({ page, context }) => {
      // Force expire the session
      await context.clearCookies();

      // Try to access a protected page
      await page.goto('/zones/forward');

      // Should redirect to login
      await expect(page).toHaveURL(/.*login/);
      await expect(page.locator('[data-testid="session-error"]')).toBeVisible();
    });

    test('should prevent CSRF attacks with token validation', async ({ page }) => {
      // Visit a page with a form
      await page.locator('[data-testid="add-master-zone-link"]').click();

      // Tamper with the CSRF token
      await page.locator('[name="csrf_token"]').evaluate((el) => el.value = 'invalid-token');

      // Try to submit the form
      await page.locator('[data-testid="zone-name-input"]').fill('csrf-test.com');
      await page.locator('[data-testid="add-zone-button"]').click();

      // Should show CSRF error
      await expect(page.locator('[data-testid="csrf-error"]')).toBeVisible();
    });
  });

  test.describe('Concurrent Actions', () => {
    test('should handle rapid sequential form submissions', async ({ page }) => {
      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill('concurrent-test.com');

      // Attempt double-click on submit button
      await page.locator('[data-testid="add-zone-button"]').dblclick();

      // Check we end up on the correct page
      await expect(page).toHaveURL(/.*zones\/forward/);

      // Clean up
      await page.locator('tr:has-text("concurrent-test.com")').locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();
    });
  });

  test.describe('Pagination Edge Cases', () => {
    test('should handle navigation to non-existent pages', async ({ page }) => {
      // Navigate to zones list
      await page.locator('[data-testid="list-zones-link"]').click();

      // Try to access an invalid page number
      await page.goto('/zones/forward?letter=all&start=9999');

      // Should show first page or error message
      await expect(page.locator('[data-testid="zones-table"]')).toBeVisible();
    });
  });

  test.describe('Browser Navigation', () => {
    test('should handle browser back button correctly', async ({ page }) => {
      // Navigate to a page
      await page.locator('[data-testid="add-master-zone-link"]').click();

      // Fill out the form
      await page.locator('[data-testid="zone-name-input"]').fill('navigation-test.com');
      await page.locator('[data-testid="add-zone-button"]').click();

      // Back button
      await page.goBack();

      // Check form state
      await expect(page.locator('[data-testid="zone-name-input"]')).toBeVisible();

      // Go forward
      await page.goForward();

      // Should be on forward zones list
      await expect(page).toHaveURL(/.*zones\/forward/);

      // Clean up
      await page.locator('tr:has-text("navigation-test.com")').locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();
    });
  });

  test.describe('Direct URL Access', () => {
    test('should handle direct access to edit pages with invalid IDs', async ({ page }) => {
      // Try to access a non-existent record
      await page.goto('/zones/1/records/999999999/edit');

      // Should show error or redirect
      await expect(page.locator('[data-testid="error-message"]')).toBeVisible();
    });

    test('should prevent unauthorized access to admin functions', async ({ page }) => {
      // First logout
      await page.locator('[data-testid="logout-link"]').click();

      // Try to access admin page directly
      await page.goto('/users');

      // Should redirect to login
      await expect(page).toHaveURL(/.*login/);
    });
  });

  test.describe('Special Characters Handling', () => {
    test('should properly escape HTML in user input display', async ({ page }) => {
      // Create a zone with HTML tags
      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill('special-char-test.com');
      await page.locator('[data-testid="add-zone-button"]').click();

      // Navigate to records
      await page.locator('[data-testid="list-zones-link"]').click();
      await page.locator('tr:has-text("special-char-test.com")').locator('[data-testid^="edit-zone-"]').click();

      // Add a TXT record with HTML
      await page.locator('[data-testid="record-type-select"]').selectOption('TXT');
      await page.locator('[data-testid="record-name-input"]').fill('html-test');
      await page.locator('[data-testid="record-content-input"]').fill('<script>alert("XSS")</script>');
      await page.locator('[data-testid="add-record-button"]').click();

      // HTML should be escaped in the display
      await expect(page.getByText('<script>', { exact: false })).toBeVisible();

      // Clean up
      await page.locator('[data-testid="list-zones-link"]').click();
      await page.locator('tr:has-text("special-char-test.com")').locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();
    });
  });
});
