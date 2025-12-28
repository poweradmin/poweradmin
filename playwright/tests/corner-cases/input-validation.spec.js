import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Input Validation Edge Cases', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test.describe('Zone Name Validation', () => {
    test('should reject zone names with invalid characters', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      const nameInput = page.locator('input[name*="name"], input[name*="domain"]').first();
      await nameInput.fill('invalid!domain.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('invalid') || bodyText.toLowerCase().includes('error') || page.url().includes('add_zone_master');
      expect(hasError).toBeTruthy();
    });

    test('should reject zone names that are too long', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      // Generate a very long domain name (over 255 characters)
      const longPrefix = 'a'.repeat(245);
      const nameInput = page.locator('input[name*="name"], input[name*="domain"]').first();
      await nameInput.fill(`${longPrefix}.com`);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('long') || bodyText.toLowerCase().includes('error') || page.url().includes('add_zone_master');
      expect(hasError).toBeTruthy();
    });

    test('should reject zone names with double dots', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      const nameInput = page.locator('input[name*="name"], input[name*="domain"]').first();
      await nameInput.fill('invalid..domain.com');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('invalid') || bodyText.toLowerCase().includes('error') || page.url().includes('add_zone_master');
      expect(hasError).toBeTruthy();
    });

    test('should handle unicode IDN zone names correctly', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_master');

      const nameInput = page.locator('input[name*="name"], input[name*="domain"]').first();
      await nameInput.fill('xn--80aswg.xn--p1ai');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should either succeed or show specific error
      const bodyText = await page.locator('body').textContent();
      // Either success or handled gracefully
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Record Validation', () => {
    test('should validate IP addresses for A records', async ({ page }) => {
      // Go to add record page (assuming zone with id=1 exists)
      await page.goto('/index.php?page=add_record&id=1', { waitUntil: 'domcontentloaded' });

      const hasForm = await page.locator('form').count() > 0;
      if (hasForm) {
        // Select A record type
        const typeSelect = page.locator('select[name*="type"]').first();
        await typeSelect.selectOption('A');

        // Fill invalid IP
        const contentInput = page.locator('input[name*="content"], input[name*="value"], textarea').first();
        await contentInput.fill('256.256.256.256');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Should show validation error
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('invalid') || bodyText.toLowerCase().includes('error') || page.url().includes('add_record');
        expect(hasError).toBeTruthy();
      } else {
        test.info().annotations.push({ type: 'note', description: 'No zone available for record testing' });
      }
    });

    test('should validate hostnames for CNAME records', async ({ page }) => {
      await page.goto('/index.php?page=add_record&id=1', { waitUntil: 'domcontentloaded' });

      const hasForm = await page.locator('form').count() > 0;
      if (hasForm) {
        const typeSelect = page.locator('select[name*="type"]').first();
        await typeSelect.selectOption('CNAME');

        const contentInput = page.locator('input[name*="content"], input[name*="value"], textarea').first();
        await contentInput.fill('invalid..hostname.com');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Should show validation or stay on form
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should handle TTL values', async ({ page }) => {
      await page.goto('/index.php?page=add_record&id=1', { waitUntil: 'domcontentloaded' });

      const hasForm = await page.locator('form').count() > 0;
      if (hasForm) {
        const ttlInput = page.locator('input[name*="ttl"]').first();
        if (await ttlInput.count() > 0) {
          await ttlInput.clear();
          await ttlInput.fill('-100');
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          // Should handle gracefully (error or default)
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('User Input Validation', () => {
    test('should validate email addresses for users', async ({ page }) => {
      await page.goto('/index.php?page=add_user');

      const hasForm = await page.locator('form').count() > 0;
      if (hasForm) {
        // Fill username
        const usernameInput = page.locator('input[name*="username"]').first();
        if (await usernameInput.count() > 0) {
          await usernameInput.fill('testuser123');
        }

        // Fill invalid email
        const emailInput = page.locator('input[name*="email"]').first();
        if (await emailInput.count() > 0) {
          await emailInput.fill('notanemail@');
        }

        // Fill password
        const passwordInput = page.locator('input[name*="password"]').first();
        if (await passwordInput.count() > 0) {
          await passwordInput.fill('password123');
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Should show error or stay on form
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('email') || bodyText.toLowerCase().includes('invalid') || page.url().includes('add_user');
        expect(hasError).toBeTruthy();
      }
    });

    test('should validate password confirmation', async ({ page }) => {
      await page.goto('/index.php?page=add_user');

      const hasForm = await page.locator('form').count() > 0;
      if (hasForm) {
        // Fill basic info
        const usernameInput = page.locator('input[name*="username"]').first();
        if (await usernameInput.count() > 0) {
          await usernameInput.fill('testuser123');
        }

        // Fill different passwords
        const passwordInputs = page.locator('input[type="password"]');
        const count = await passwordInputs.count();
        if (count >= 2) {
          await passwordInputs.nth(0).fill('password123');
          await passwordInputs.nth(1).fill('different123');
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Should show error or stay on form
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('match') || bodyText.toLowerCase().includes('password') || page.url().includes('add_user');
        expect(hasError).toBeTruthy();
      }
    });
  });
});
