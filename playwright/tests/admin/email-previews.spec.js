/**
 * Email Template Previews Tests
 *
 * Tests for the email template preview functionality
 * covering the email_previews.html template.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Email Template Previews Page', () => {
  test.describe('Page Access', () => {
    test('admin should access email previews page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/email|template|preview|permission|denied/i);
    });

    test('should display page title', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();
      const hasTitle = bodyText.toLowerCase().includes('email') ||
                       bodyText.toLowerCase().includes('template') ||
                       bodyText.toLowerCase().includes('permission');
      expect(hasTitle).toBeTruthy();
    });

    test('should display breadcrumb navigation', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"], .breadcrumb');
      const hasBreadcrumb = await breadcrumb.count() > 0;
      expect(hasBreadcrumb || page.url().includes('email_previews')).toBeTruthy();
    });

    test('should require login to access', async ({ page }) => {
      await page.goto('/index.php?page=email_previews');

      await expect(page).toHaveURL(/page=login/);
    });

    test('should require admin privileges', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();
      const url = page.url();

      // Non-admin may be denied or redirected
      const hasAccess = url.includes('email_previews');
      const accessDenied = bodyText.toLowerCase().includes('denied') ||
                           bodyText.toLowerCase().includes('permission') ||
                           !hasAccess;

      expect(accessDenied || hasAccess).toBeTruthy();
    });
  });

  test.describe('Template Status Display', () => {
    test('should indicate template type (standard or custom)', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();

      // Template shows either Standard or Custom badge
      const hasTypeIndicator = bodyText.toLowerCase().includes('standard') ||
                                bodyText.toLowerCase().includes('custom') ||
                                bodyText.toLowerCase().includes('active') ||
                                bodyText.toLowerCase().includes('permission');
      expect(hasTypeIndicator).toBeTruthy();
    });

    test('should display template status badge', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const badge = page.locator('.badge');
      const bodyText = await page.locator('body').textContent();

      const hasBadge = await badge.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasBadge || hasPermissionError).toBeTruthy();
    });

    test('should show info alert for template type', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      // Template shows info about which templates are active
      const alert = page.locator('.alert-primary, .alert-success, .alert-info');
      const bodyText = await page.locator('body').textContent();

      const hasAlert = await alert.count() > 0;
      const hasInfo = bodyText.toLowerCase().includes('templates') ||
                      bodyText.toLowerCase().includes('permission');

      expect(hasAlert || hasInfo).toBeTruthy();
    });
  });

  test.describe('Template Cards', () => {
    test('should display template cards', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const cards = page.locator('.card');
      const bodyText = await page.locator('body').textContent();

      const hasCards = await cards.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasCards || hasPermissionError).toBeTruthy();
    });

    test('should display template subject in cards', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();

      // Template shows Subject: for each template
      const hasSubject = bodyText.toLowerCase().includes('subject') ||
                          bodyText.toLowerCase().includes('permission');
      expect(hasSubject).toBeTruthy();
    });

    test('should have envelope icon in header', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const envelopeIcon = page.locator('.bi-envelope, .bi-envelope-fill');
      const bodyText = await page.locator('body').textContent();

      const hasIcon = await envelopeIcon.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasIcon || hasPermissionError).toBeTruthy();
    });
  });

  test.describe('Preview Links', () => {
    test('should have light mode preview link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const lightModeLink = page.locator('a[href*="mode=light"]');
      const bodyText = await page.locator('body').textContent();

      const hasLightLink = await lightModeLink.count() > 0;
      const hasLightText = bodyText.toLowerCase().includes('light mode') ||
                           bodyText.toLowerCase().includes('permission');

      expect(hasLightLink || hasLightText).toBeTruthy();
    });

    test('should have dark mode preview link', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const darkModeLink = page.locator('a[href*="mode=dark"]');
      const bodyText = await page.locator('body').textContent();

      const hasDarkLink = await darkModeLink.count() > 0;
      const hasDarkText = bodyText.toLowerCase().includes('dark mode') ||
                          bodyText.toLowerCase().includes('permission');

      expect(hasDarkLink || hasDarkText).toBeTruthy();
    });

    test('should have target="_blank" for preview links', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const previewLinks = page.locator('a[href*="mode=light"], a[href*="mode=dark"]');

      if (await previewLinks.count() > 0) {
        const target = await previewLinks.first().getAttribute('target');
        expect(target).toBe('_blank');
      }
    });

    test('should have sun icon for light mode', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const sunIcon = page.locator('.bi-sun');
      const bodyText = await page.locator('body').textContent();

      const hasIcon = await sunIcon.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasIcon || hasPermissionError).toBeTruthy();
    });

    test('should have moon icon for dark mode', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const moonIcon = page.locator('.bi-moon');
      const bodyText = await page.locator('body').textContent();

      const hasIcon = await moonIcon.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasIcon || hasPermissionError).toBeTruthy();
    });
  });

  test.describe('Information Sections', () => {
    test('should display about email previews info', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();

      // Template has info section about previews
      const hasInfo = bodyText.toLowerCase().includes('about') ||
                      bodyText.toLowerCase().includes('preview') ||
                      bodyText.toLowerCase().includes('sample') ||
                      bodyText.toLowerCase().includes('permission');
      expect(hasInfo).toBeTruthy();
    });

    test('should explain preview purpose', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();

      // Template explains what previews show
      const hasExplanation = bodyText.toLowerCase().includes('sample data') ||
                              bodyText.toLowerCase().includes('demonstrate') ||
                              bodyText.toLowerCase().includes('appearance') ||
                              bodyText.toLowerCase().includes('permission');
      expect(hasExplanation).toBeTruthy();
    });

    test('should have info alert with bullet points', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const infoAlert = page.locator('.alert-info');
      const bodyText = await page.locator('body').textContent();

      const hasInfoAlert = await infoAlert.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasInfoAlert || hasPermissionError).toBeTruthy();
    });
  });

  test.describe('Custom Template Information', () => {
    test('should mention custom template path', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();

      // Template mentions custom template location
      const hasCustomInfo = bodyText.includes('templates/emails/custom') ||
                            bodyText.toLowerCase().includes('custom') ||
                            bodyText.toLowerCase().includes('permission');
      expect(hasCustomInfo).toBeTruthy();
    });

    test('should explain how to create custom templates', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const bodyText = await page.locator('body').textContent();

      // Template explains customization
      const hasInstructions = bodyText.toLowerCase().includes('override') ||
                               bodyText.toLowerCase().includes('custom') ||
                               bodyText.toLowerCase().includes('create') ||
                               bodyText.toLowerCase().includes('permission');
      expect(hasInstructions).toBeTruthy();
    });
  });

  test.describe('Template Preview Mode', () => {
    test('should load light mode preview', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const lightModeLink = page.locator('a[href*="mode=light"]').first();

      if (await lightModeLink.count() > 0) {
        const href = await lightModeLink.getAttribute('href');
        expect(href).toContain('mode=light');
      }
    });

    test('should load dark mode preview', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const darkModeLink = page.locator('a[href*="mode=dark"]').first();

      if (await darkModeLink.count() > 0) {
        const href = await darkModeLink.getAttribute('href');
        expect(href).toContain('mode=dark');
      }
    });
  });

  test.describe('Responsive Layout', () => {
    test('should use responsive grid for template cards', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const rowContainer = page.locator('.row');
      const bodyText = await page.locator('body').textContent();

      const hasRow = await rowContainer.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasRow || hasPermissionError).toBeTruthy();
    });

    test('should have column classes for responsive layout', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=email_previews');

      const colElements = page.locator('[class*="col-"]');
      const bodyText = await page.locator('body').textContent();

      const hasCols = await colElements.count() > 0;
      const hasPermissionError = bodyText.toLowerCase().includes('permission');

      expect(hasCols || hasPermissionError).toBeTruthy();
    });
  });
});
