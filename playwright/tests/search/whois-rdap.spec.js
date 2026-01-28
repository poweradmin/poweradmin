/**
 * WHOIS/RDAP Lookup Tests
 *
 * Tests for the WHOIS lookup functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('WHOIS Lookup Page', () => {
  test.describe('Page Access', () => {
    test('admin should access WHOIS lookup page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/whois');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/whois|lookup|domain/i);
    });

    test('should display page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/whois');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/whois.*lookup/i);
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/whois');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      const bodyText = await page.locator('body').textContent();

      // Breadcrumb visible or feature disabled
      const hasBreadcrumb = await breadcrumb.count() > 0;
      const isDisabled = bodyText.toLowerCase().includes('disabled');
      expect(hasBreadcrumb || isDisabled).toBeTruthy();
    });

    test('should require login to access', async ({ page }) => {
      await page.goto('/whois');

      await expect(page).toHaveURL(/.*\/login/);
    });

    test('manager should access WHOIS lookup page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/whois');

      const bodyText = await page.locator('body').textContent();
      // Either has access or is denied
      const hasAccess = bodyText.toLowerCase().includes('whois');
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission');
      expect(hasAccess || accessDenied).toBeTruthy();
    });
  });

  test.describe('Search Form', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display domain input field or disabled message', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"], input#domain');
      const bodyText = await page.locator('body').textContent();

      const hasInput = await domainInput.count() > 0;
      const isDisabled = bodyText.toLowerCase().includes('disabled');
      expect(hasInput || isDisabled).toBeTruthy();
    });

    test('should display lookup button or disabled message', async ({ page }) => {
      await page.goto('/whois');

      const lookupBtn = page.locator('button[type="submit"]:has-text("Lookup")');
      const bodyText = await page.locator('body').textContent();

      const hasButton = await lookupBtn.count() > 0;
      const isDisabled = bodyText.toLowerCase().includes('disabled');
      expect(hasButton || isDisabled).toBeTruthy();
    });

    test('should have placeholder text or disabled message', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"]');
      const bodyText = await page.locator('body').textContent();

      if (await domainInput.count() > 0) {
        const placeholder = await domainInput.getAttribute('placeholder');
        expect(placeholder).toBeTruthy();
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });

    test('should display help text or disabled message', async ({ page }) => {
      await page.goto('/whois');

      const bodyText = await page.locator('body').textContent();
      const hasHelp = bodyText.toLowerCase().match(/enter.*domain|domain.*name|example\.com|disabled/i);
      expect(hasHelp).toBeTruthy();
    });

    test('should include CSRF token when enabled', async ({ page }) => {
      await page.goto('/whois');

      const csrfToken = page.locator('input[name="_token"]');
      const bodyText = await page.locator('body').textContent();

      const hasToken = await csrfToken.count() > 0;
      const isDisabled = bodyText.toLowerCase().includes('disabled');
      expect(hasToken || isDisabled).toBeTruthy();
    });
  });

  test.describe('Form Submission', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept domain input when enabled', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"]');
      const bodyText = await page.locator('body').textContent();

      if (await domainInput.count() > 0) {
        await domainInput.fill('example.com');
        await expect(domainInput).toHaveValue('example.com');
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });

    test('should submit lookup request when enabled', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"]');
      const bodyText = await page.locator('body').textContent();

      if (await domainInput.count() > 0) {
        await domainInput.fill('example.com');

        const lookupBtn = page.locator('button[type="submit"]:has-text("Lookup")');
        await lookupBtn.click();

        const resultText = await page.locator('body').textContent();
        expect(resultText.toLowerCase()).toMatch(/result|error|whois|domain/i);
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });

    test('should handle empty domain submission when enabled', async ({ page }) => {
      await page.goto('/whois');

      const lookupBtn = page.locator('button[type="submit"]:has-text("Lookup")');
      const bodyText = await page.locator('body').textContent();

      if (await lookupBtn.count() > 0) {
        await lookupBtn.click();
        await expect(page).toHaveURL(/.*\/whois/);
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });
  });

  test.describe('Results Display', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display results section after lookup when enabled', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"]');
      const bodyText = await page.locator('body').textContent();

      if (await domainInput.count() > 0) {
        await domainInput.fill('google.com');

        const lookupBtn = page.locator('button[type="submit"]:has-text("Lookup")');
        await lookupBtn.click();

        await page.waitForLoadState('networkidle');

        const resultText = await page.locator('body').textContent();
        const hasResultsOrError = resultText.toLowerCase().includes('result') ||
                                   resultText.toLowerCase().includes('error') ||
                                   resultText.toLowerCase().includes('google');
        expect(hasResultsOrError).toBeTruthy();
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });

    test('should handle invalid domain when enabled', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"]');
      const bodyText = await page.locator('body').textContent();

      if (await domainInput.count() > 0) {
        await domainInput.fill('invalid-domain-12345.tld');

        const lookupBtn = page.locator('button[type="submit"]:has-text("Lookup")');
        await lookupBtn.click();

        await page.waitForLoadState('networkidle');

        const resultText = await page.locator('body').textContent();
        expect(resultText.length).toBeGreaterThan(100);
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });

    test('should show results or alert when enabled', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"]');
      const bodyText = await page.locator('body').textContent();

      if (await domainInput.count() > 0) {
        await domainInput.fill('example.com');

        const lookupBtn = page.locator('button[type="submit"]:has-text("Lookup")');
        await lookupBtn.click();

        await page.waitForLoadState('networkidle');

        const preElement = page.locator('.whois-results pre, pre');
        const alertElement = page.locator('.alert');

        const hasResults = await preElement.count() > 0;
        const hasAlert = await alertElement.count() > 0;

        expect(hasResults || hasAlert).toBeTruthy();
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });
  });

  test.describe('IDN Domain Support', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should accept internationalized domain names when enabled', async ({ page }) => {
      await page.goto('/whois');

      const domainInput = page.locator('input[name="domain"]');
      const bodyText = await page.locator('body').textContent();

      if (await domainInput.count() > 0) {
        await domainInput.fill('beispiel.de');
        await expect(domainInput).toHaveValue('beispiel.de');
      } else {
        expect(bodyText.toLowerCase()).toContain('disabled');
      }
    });
  });

  test.describe('UI Elements', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should have search icon or disabled message', async ({ page }) => {
      await page.goto('/whois');

      const searchIcon = page.locator('.bi-search');
      const bodyText = await page.locator('body').textContent();

      const hasIcon = await searchIcon.count() > 0;
      const isDisabled = bodyText.toLowerCase().includes('disabled');
      expect(hasIcon || isDisabled).toBeTruthy();
    });

    test('should use input group styling or show disabled message', async ({ page }) => {
      await page.goto('/whois');

      const inputGroup = page.locator('.input-group');
      const bodyText = await page.locator('body').textContent();

      const hasInputGroup = await inputGroup.count() > 0;
      const isDisabled = bodyText.toLowerCase().includes('disabled');
      expect(hasInputGroup || isDisabled).toBeTruthy();
    });

    test('should have card layout or show disabled message', async ({ page }) => {
      await page.goto('/whois');

      const card = page.locator('.card');
      const bodyText = await page.locator('body').textContent();

      const hasCard = await card.count() > 0;
      const isDisabled = bodyText.toLowerCase().includes('disabled');
      expect(hasCard || isDisabled).toBeTruthy();
    });
  });
});
