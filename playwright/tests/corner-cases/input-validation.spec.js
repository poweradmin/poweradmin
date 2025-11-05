import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Input Validation Edge Cases', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test.describe('Zone Name Validation', () => {
    test.beforeEach(async ({ page }) => {
      await page.locator('[data-testid="add-master-zone-link"]').click();
    });

    test('should reject zone names with invalid characters', async ({ page }) => {
      await page.locator('[data-testid="zone-name-input"]').fill('invalid!domain.com');
      await page.locator('[data-testid="add-zone-button"]').click();

      await expect(page.locator('[data-testid="zone-name-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="zone-name-error"]')).toContainText('contains invalid characters');
    });

    test('should reject zone names that are too long', async ({ page }) => {
      // Generate a very long domain name (over 255 characters)
      const longPrefix = 'a'.repeat(245);
      await page.locator('[data-testid="zone-name-input"]').fill(`${longPrefix}.com`);
      await page.locator('[data-testid="add-zone-button"]').click();

      await expect(page.locator('[data-testid="zone-name-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="zone-name-error"]')).toContainText('too long');
    });

    test('should reject zone names with double dots', async ({ page }) => {
      await page.locator('[data-testid="zone-name-input"]').fill('invalid..domain.com');
      await page.locator('[data-testid="add-zone-button"]').click();

      await expect(page.locator('[data-testid="zone-name-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="zone-name-error"]')).toContainText('consecutive dots');
    });

    test('should handle unicode IDN zone names correctly', async ({ page }) => {
      await page.locator('[data-testid="zone-name-input"]').fill('xn--80aswg.xn--p1ai');
      await page.locator('[data-testid="add-zone-button"]').click();

      // This should succeed or fail based on whether IDN is supported
      // We check for either success or a specific IDN-related error
      const hasAlert = await page.locator('[data-testid="alert-message"]').count();

      if (hasAlert > 0) {
        await expect(page.locator('[data-testid="alert-message"]')).toContainText('Zone has been added successfully');

        // Clean up
        await page.locator('[data-testid="list-zones-link"]').click();
        await page.locator('tr:has-text("xn--80aswg.xn--p1ai")').locator('[data-testid^="delete-zone-"]').click();
        await page.locator('[data-testid="confirm-delete-zone"]').click();
      } else {
        await expect(page.locator('[data-testid="zone-name-error"]')).toContainText('IDN');
      }
    });
  });

  test.describe('Record Validation', () => {
    test.beforeEach(async ({ page }) => {
      // Create a test zone
      await page.locator('[data-testid="add-master-zone-link"]').click();
      await page.locator('[data-testid="zone-name-input"]').fill('validation-test.com');
      await page.locator('[data-testid="add-zone-button"]').click();

      // Navigate to records
      await page.locator('[data-testid="list-zones-link"]').click();
      await page.locator('tr:has-text("validation-test.com")').locator('[data-testid^="edit-zone-"]').click();
    });

    test('should reject invalid IP addresses for A records', async ({ page }) => {
      await page.locator('[data-testid="record-type-select"]').selectOption('A');
      await page.locator('[data-testid="record-name-input"]').fill('www');
      await page.locator('[data-testid="record-content-input"]').fill('256.256.256.256');
      await page.locator('[data-testid="add-record-button"]').click();

      await expect(page.locator('[data-testid="record-content-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="record-content-error"]')).toContainText('invalid IP address');
    });

    test('should reject invalid hostnames for CNAME records', async ({ page }) => {
      await page.locator('[data-testid="record-type-select"]').selectOption('CNAME');
      await page.locator('[data-testid="record-name-input"]').fill('mail');
      await page.locator('[data-testid="record-content-input"]').fill('invalid..hostname.com');
      await page.locator('[data-testid="add-record-button"]').click();

      await expect(page.locator('[data-testid="record-content-error"]')).toBeVisible();
    });

    test('should reject very long record content', async ({ page }) => {
      await page.locator('[data-testid="record-type-select"]').selectOption('TXT');
      await page.locator('[data-testid="record-name-input"]').fill('txt');

      // Generate a very long TXT record
      const longContent = 'a'.repeat(2000);
      await page.locator('[data-testid="record-content-input"]').fill(longContent);
      await page.locator('[data-testid="add-record-button"]').click();

      await expect(page.locator('[data-testid="record-content-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="record-content-error"]')).toContainText('too long');
    });

    test('should handle invalid TTL values', async ({ page }) => {
      await page.locator('[data-testid="record-type-select"]').selectOption('A');
      await page.locator('[data-testid="record-name-input"]').fill('www');
      await page.locator('[data-testid="record-content-input"]').fill('192.168.1.1');
      await page.locator('[data-testid="record-ttl-input"]').clear();
      await page.locator('[data-testid="record-ttl-input"]').fill('-100');
      await page.locator('[data-testid="add-record-button"]').click();

      await expect(page.locator('[data-testid="record-ttl-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="record-ttl-error"]')).toContainText('must be positive');
    });

    test.afterAll(async ({ browser }) => {
      // Clean up test zone
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.locator('[data-testid="list-zones-link"]').click();
      await page.locator('tr:has-text("validation-test.com")').locator('[data-testid^="delete-zone-"]').click();
      await page.locator('[data-testid="confirm-delete-zone"]').click();

      await page.close();
    });
  });

  test.describe('User Input Validation', () => {
    test('should reject invalid email addresses for users', async ({ page }) => {
      await page.locator('[data-testid="users-link"]').click();
      await page.locator('[data-testid="add-user-link"]').click();

      await page.locator('[data-testid="username-input"]').fill('testuser123');
      await page.locator('[data-testid="fullname-input"]').fill('Test User');
      await page.locator('[data-testid="email-input"]').fill('notanemail@');
      await page.locator('[data-testid="password-input"]').fill('password123');
      await page.locator('[data-testid="password-confirm-input"]').fill('password123');

      await page.locator('[data-testid="add-user-button"]').click();

      await expect(page.locator('[data-testid="email-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="email-error"]')).toContainText('valid email');
    });

    test('should reject mismatched passwords', async ({ page }) => {
      await page.locator('[data-testid="users-link"]').click();
      await page.locator('[data-testid="add-user-link"]').click();

      await page.locator('[data-testid="username-input"]').fill('testuser123');
      await page.locator('[data-testid="fullname-input"]').fill('Test User');
      await page.locator('[data-testid="email-input"]').fill('test@example.com');
      await page.locator('[data-testid="password-input"]').fill('password123');
      await page.locator('[data-testid="password-confirm-input"]').fill('different123');

      await page.locator('[data-testid="add-user-button"]').click();

      await expect(page.locator('[data-testid="password-confirm-error"]')).toBeVisible();
      await expect(page.locator('[data-testid="password-confirm-error"]')).toContainText('match');
    });

    test('should enforce password policy if configured', async ({ page }) => {
      await page.locator('[data-testid="users-link"]').click();
      await page.locator('[data-testid="add-user-link"]').click();

      await page.locator('[data-testid="username-input"]').fill('testuser123');
      await page.locator('[data-testid="fullname-input"]').fill('Test User');
      await page.locator('[data-testid="email-input"]').fill('test@example.com');
      await page.locator('[data-testid="password-input"]').fill('weak');
      await page.locator('[data-testid="password-confirm-input"]').fill('weak');

      await page.locator('[data-testid="add-user-button"]').click();

      // Check if password policy is enforced
      const hasPasswordError = await page.locator('[data-testid="password-error"]').count();
      if (hasPasswordError > 0) {
        await expect(page.locator('[data-testid="password-error"]')).toContainText('policy');
      }
    });
  });
});
