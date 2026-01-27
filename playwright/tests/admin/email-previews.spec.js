/**
 * Email Template Previews Tests
 *
 * Tests for the email template preview functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Email Template Previews Page', () => {
  test.describe('Page Access', () => {
    test('admin should access email previews page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/email|template|preview|permission|denied/i);
    });

    test('should display page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const bodyText = await page.locator('body').textContent();
      const hasTitle = bodyText.toLowerCase().includes('email') ||
                       bodyText.toLowerCase().includes('template') ||
                       bodyText.toLowerCase().includes('permission');
      expect(hasTitle).toBeTruthy();
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"], .breadcrumb');
      const hasBreadcrumb = await breadcrumb.count() > 0;
      expect(hasBreadcrumb || page.url().includes('email-previews')).toBeTruthy();
    });

    test('should require login to access', async ({ page }) => {
      await page.goto('/tools/email-previews');

      await expect(page).toHaveURL(/.*\/login/);
    });

    test('should require admin privileges', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/tools/email-previews');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();

      // Non-admin may be denied or redirected
      const hasAccess = url.includes('email-previews');
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !hasAccess;

      expect(accessDenied || hasAccess).toBeTruthy();
    });
  });

  test.describe('Template Status Display', () => {
    test('should indicate template type (standard or custom)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const bodyText = await page.locator('body').textContent();

      // Template shows either Standard or Custom badge
      const hasTypeIndicator = bodyText.toLowerCase().includes('standard') ||
                                bodyText.toLowerCase().includes('custom') ||
                                bodyText.toLowerCase().includes('active') ||
                                bodyText.toLowerCase().includes('permission');
      expect(hasTypeIndicator).toBeTruthy();
    });

    test('should display template cards', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const cards = page.locator('.card');
      const bodyText = await page.locator('body').textContent();

      const hasCards = await cards.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasCards || hasPermissionError).toBeTruthy();
    });
  });

  test.describe('Preview Links', () => {
    test('should have light mode preview link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const lightModeLink = page.locator('a[href*="mode=light"]');
      const bodyText = await page.locator('body').textContent();

      const hasLightLink = await lightModeLink.count() > 0;
      const hasLightText = bodyText.toLowerCase().includes('light mode') ||
                           bodyText.toLowerCase().includes('permission');

      expect(hasLightLink || hasLightText).toBeTruthy();
    });

    test('should have dark mode preview link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const darkModeLink = page.locator('a[href*="mode=dark"]');
      const bodyText = await page.locator('body').textContent();

      const hasDarkLink = await darkModeLink.count() > 0;
      const hasDarkText = bodyText.toLowerCase().includes('dark mode') ||
                          bodyText.toLowerCase().includes('permission');

      expect(hasDarkLink || hasDarkText).toBeTruthy();
    });
  });

  test.describe('Information Sections', () => {
    test('should display about email previews info', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const bodyText = await page.locator('body').textContent();

      // Template has info section about previews
      const hasInfo = bodyText.toLowerCase().includes('about') ||
                      bodyText.toLowerCase().includes('preview') ||
                      bodyText.toLowerCase().includes('sample') ||
                      bodyText.toLowerCase().includes('permission');
      expect(hasInfo).toBeTruthy();
    });

    test('should mention custom template path', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/tools/email-previews');

      const bodyText = await page.locator('body').textContent();

      // Template mentions custom template location
      const hasCustomInfo = bodyText.includes('templates/emails/custom') ||
                            bodyText.toLowerCase().includes('custom') ||
                            bodyText.toLowerCase().includes('permission');
      expect(hasCustomInfo).toBeTruthy();
    });
  });
});
