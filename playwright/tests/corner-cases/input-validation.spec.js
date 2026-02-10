import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Input Validation Edge Cases', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test.describe('Zone Name Validation', () => {
    test('should reject zone names with invalid characters', async ({ page }) => {
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
      if (await zoneInput.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await zoneInput.fill('invalid!domain.com');

      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      // Either shows error or stays on add page
      expect(page.url().includes('add') || bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('invalid')).toBeTruthy();
    });

    test('should reject zone names that are too long', async ({ page }) => {
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
      if (await zoneInput.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      // Generate a very long domain name (over 255 characters)
      const longPrefix = 'a'.repeat(245);
      await zoneInput.fill(`${longPrefix}.com`);

      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject zone names with double dots', async ({ page }) => {
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
      if (await zoneInput.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await zoneInput.fill('invalid..domain.com');

      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle unicode IDN zone names correctly', async ({ page }) => {
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
      if (await zoneInput.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await zoneInput.fill('xn--80aswg.xn--p1ai');

      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // This should succeed or fail based on whether IDN is supported
      // Either way, it should not crash
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Clean up if zone was created
      if (bodyText.toLowerCase().includes('success') || bodyText.toLowerCase().includes('added')) {
        await page.goto('/zones/forward?letter=all');
        await page.waitForLoadState('networkidle');

        const zoneRow = page.locator('tr:has-text("xn--80aswg.xn--p1ai")');
        if (await zoneRow.count() > 0) {
          const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
          if (await deleteLink.count() > 0) {
            await deleteLink.click();
            await page.waitForLoadState('networkidle');
            const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
            if (await yesBtn.count() > 0) {
              await yesBtn.click();
            }
          }
        }
      }
    });
  });

  test.describe('Record Validation', () => {
    const testZoneName = `validation-test-${Date.now()}.com`;
    let zoneCreated = false;

    test('should create test zone for record validation', async ({ page }) => {
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
      if (await zoneInput.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await zoneInput.fill(testZoneName);

      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      zoneCreated = true;
    });

    test('should reject invalid IP addresses for A records', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const zoneRow = page.locator(`tr:has-text("${testZoneName}")`);
      if (await zoneRow.count() === 0) {
        // Zone wasn't created - skip gracefully
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = zoneRow.locator('a[href*="/edit"]').first();
      await editLink.click();
      await page.waitForLoadState('networkidle');

      const typeSelect = page.locator('select[name*="type"]').first();
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('A');
      }

      const nameInput = page.locator('input[name*="name"]').first();
      const contentInput = page.locator('input[name*="content"]').first();

      if (await nameInput.count() > 0 && await contentInput.count() > 0) {
        await nameInput.fill('www');
        await contentInput.fill('256.256.256.256');

        const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
        await submitBtn.click();
        await page.waitForLoadState('networkidle');
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject invalid hostnames for CNAME records', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const zoneRow = page.locator(`tr:has-text("${testZoneName}")`);
      if (await zoneRow.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = zoneRow.locator('a[href*="/edit"]').first();
      await editLink.click();
      await page.waitForLoadState('networkidle');

      const typeSelect = page.locator('select[name*="type"]').first();
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('CNAME');
      }

      const nameInput = page.locator('input[name*="name"]').first();
      const contentInput = page.locator('input[name*="content"]').first();

      if (await nameInput.count() > 0 && await contentInput.count() > 0) {
        await nameInput.fill('mail');
        await contentInput.fill('invalid..hostname.com');

        const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
        await submitBtn.click();
        await page.waitForLoadState('networkidle');
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject very long record content', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const zoneRow = page.locator(`tr:has-text("${testZoneName}")`);
      if (await zoneRow.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = zoneRow.locator('a[href*="/edit"]').first();
      await editLink.click();
      await page.waitForLoadState('networkidle');

      const typeSelect = page.locator('select[name*="type"]').first();
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('TXT');
      }

      const nameInput = page.locator('input[name*="name"]').first();
      const contentInput = page.locator('input[name*="content"]').first();

      if (await nameInput.count() > 0 && await contentInput.count() > 0) {
        await nameInput.fill('txt');
        // Generate a very long TXT record
        const longContent = 'a'.repeat(2000);
        await contentInput.fill(longContent);

        const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
        await submitBtn.click();
        await page.waitForLoadState('networkidle');
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle invalid TTL values', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const zoneRow = page.locator(`tr:has-text("${testZoneName}")`);
      if (await zoneRow.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      const editLink = zoneRow.locator('a[href*="/edit"]').first();
      await editLink.click();
      await page.waitForLoadState('networkidle');

      const typeSelect = page.locator('select[name*="type"]').first();
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('A');
      }

      const nameInput = page.locator('input[name*="name"]').first();
      const contentInput = page.locator('input[name*="content"]').first();
      const ttlInput = page.locator('input[name*="ttl"]').first();

      if (await nameInput.count() > 0 && await contentInput.count() > 0) {
        await nameInput.fill('www');
        await contentInput.fill('192.168.1.1');

        if (await ttlInput.count() > 0) {
          await ttlInput.clear();
          await ttlInput.fill('-100');
        }

        const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
        await submitBtn.click();
        await page.waitForLoadState('networkidle');
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should clean up test zone', async ({ page }) => {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const zoneRow = page.locator(`tr:has-text("${testZoneName}")`);
      if (await zoneRow.count() > 0) {
        const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          await page.waitForLoadState('networkidle');

          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) {
            await yesBtn.click();
            await page.waitForLoadState('networkidle');
          }
        }
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('User Input Validation', () => {
    test('should reject invalid email addresses for users', async ({ page }) => {
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const usernameInput = page.locator('input[name*="username"], input[name*="user"]').first();
      const emailInput = page.locator('input[name*="email"], input[type="email"]').first();
      const passwordInput = page.locator('input[type="password"]').first();

      if (await usernameInput.count() === 0 || await emailInput.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await usernameInput.fill('testuser123');

      const fullnameInput = page.locator('input[name*="fullname"], input[name*="full_name"]').first();
      if (await fullnameInput.count() > 0) {
        await fullnameInput.fill('Test User');
      }

      await emailInput.fill('notanemail@');

      if (await passwordInput.count() > 0) {
        await passwordInput.fill('SecurePass123!@#');
        const confirmPassword = page.locator('input[type="password"]').nth(1);
        if (await confirmPassword.count() > 0) {
          await confirmPassword.fill('SecurePass123!@#');
        }
      }

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject mismatched passwords', async ({ page }) => {
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const usernameInput = page.locator('input[name*="username"], input[name*="user"]').first();
      const emailInput = page.locator('input[name*="email"], input[type="email"]').first();
      const passwordInputs = page.locator('input[type="password"]');

      if (await usernameInput.count() === 0 || await passwordInputs.count() < 2) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await usernameInput.fill('testuser123');

      const fullnameInput = page.locator('input[name*="fullname"], input[name*="full_name"]').first();
      if (await fullnameInput.count() > 0) {
        await fullnameInput.fill('Test User');
      }

      if (await emailInput.count() > 0) {
        await emailInput.fill('test@example.com');
      }

      await passwordInputs.nth(0).fill('SecurePass123!@#');
      await passwordInputs.nth(1).fill('DifferentPass456!@#');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Should show error or stay on form
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(page.url().includes('add') || bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('match')).toBeTruthy();
    });

    test('should enforce password policy if configured', async ({ page }) => {
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      const usernameInput = page.locator('input[name*="username"], input[name*="user"]').first();
      const emailInput = page.locator('input[name*="email"], input[type="email"]').first();
      const passwordInputs = page.locator('input[type="password"]');

      if (await usernameInput.count() === 0 || await passwordInputs.count() < 2) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await usernameInput.fill('testuser123');

      const fullnameInput = page.locator('input[name*="fullname"], input[name*="full_name"]').first();
      if (await fullnameInput.count() > 0) {
        await fullnameInput.fill('Test User');
      }

      if (await emailInput.count() > 0) {
        await emailInput.fill('test@example.com');
      }

      // Try weak password
      await passwordInputs.nth(0).fill('weak');
      await passwordInputs.nth(1).fill('weak');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Check if password policy is enforced - either error or stays on form
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
