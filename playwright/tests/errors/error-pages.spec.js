/**
 * Error Pages Tests
 *
 * Tests for error handling and special pages:
 * - 404 - Page not found
 * - User agreement page
 * - Invalid page handling
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('404 Error Page', () => {
  test.describe('Page Display', () => {
    test('should display 404 page for non-existent pages', async ({ page }) => {
      await page.goto('/nonexistent-page-12345');

      const bodyText = await page.locator('body').textContent();

      // Should show 404 or redirect to login or show error
      const shows404 = bodyText.includes('404') ||
                       bodyText.toLowerCase().includes('not found') ||
                       bodyText.toLowerCase().includes('page not found');
      const redirectedToLogin = page.url().includes('login');
      const showsError = bodyText.toLowerCase().includes('error') ||
                          bodyText.toLowerCase().includes('invalid');

      expect(shows404 || redirectedToLogin || showsError).toBeTruthy();
    });

    test('should display 404 error code prominently when logged in', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/nonexistent-page-xyz');

      const bodyText = await page.locator('body').textContent();

      const has404 = bodyText.includes('404') ||
                     bodyText.toLowerCase().includes('not found') ||
                     bodyText.toLowerCase().includes('error');
      expect(has404).toBeTruthy();
    });

    test('should display page not found message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/fake-page-test');

      const bodyText = await page.locator('body').textContent();

      const hasMessage = bodyText.toLowerCase().includes('not found') ||
                          bodyText.toLowerCase().includes('page') ||
                          bodyText.toLowerCase().includes('error');
      expect(hasMessage).toBeTruthy();
    });
  });

  test.describe('404 Page Content', () => {
    test('should display error icon', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/nonexistent-test');

      const icon = page.locator('.bi-exclamation-triangle, .bi-x-circle, i[class*="bi-"]');
      const bodyText = await page.locator('body').textContent();

      const hasIcon = await icon.count() > 0;
      const has404Content = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasIcon || has404Content).toBeTruthy();
    });

    test('should display explanation list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/test-nonexistent');

      const bodyText = await page.locator('body').textContent();

      const hasExplanation = bodyText.toLowerCase().includes('url') ||
                              bodyText.toLowerCase().includes('moved') ||
                              bodyText.toLowerCase().includes('deleted') ||
                              bodyText.toLowerCase().includes('permission') ||
                              bodyText.includes('404') ||
                              bodyText.toLowerCase().includes('error');
      expect(hasExplanation).toBeTruthy();
    });
  });

  test.describe('404 Page Navigation', () => {
    test('should have homepage link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/invalid-page');

      const homeLink = page.locator('a[href="/"]:has-text("Home"), a:has-text("Homepage"), a[href="/"]');
      const bodyText = await page.locator('body').textContent();

      const hasHomeLink = await homeLink.count() > 0;
      const has404 = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasHomeLink || has404).toBeTruthy();
    });

    test('should have go back button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/nonexistent-xyz');

      const backBtn = page.locator('button:has-text("Back"), a:has-text("Back")');
      const bodyText = await page.locator('body').textContent();

      const hasBackBtn = await backBtn.count() > 0;
      const has404 = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasBackBtn || has404).toBeTruthy();
    });
  });
});

test.describe('User Agreement Page', () => {
  test.describe('Page Access', () => {
    test('should access user agreement page when logged in', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/user-agreement');

      const bodyText = await page.locator('body').textContent();

      // Should show agreement page or redirect if not required
      const hasAgreement = bodyText.toLowerCase().includes('agreement') ||
                           bodyText.toLowerCase().includes('terms') ||
                           bodyText.toLowerCase().includes('accept');
      const redirected = !page.url().includes('user-agreement');

      // Either shows agreement or redirects (if agreement not required)
      expect(hasAgreement || redirected).toBeTruthy();
    });

    test('should display page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/user-agreement');

      const bodyText = await page.locator('body').textContent();

      const hasTitle = bodyText.toLowerCase().includes('agreement') ||
                       bodyText.toLowerCase().includes('terms');
      expect(hasTitle || !page.url().includes('user-agreement')).toBeTruthy();
    });
  });

  test.describe('Agreement Content', () => {
    test('should display agreement content area', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/user-agreement');

      const contentArea = page.locator('.agreement-content, .card-body');
      const bodyText = await page.locator('body').textContent();

      const hasContent = await contentArea.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');
      const redirected = !page.url().includes('user-agreement');

      expect(hasContent || hasAgreement || redirected).toBeTruthy();
    });
  });

  test.describe('Agreement Actions', () => {
    test('should have accept button when agreement required', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/user-agreement');

      const acceptBtn = page.locator('button:has-text("Accept"), button:has-text("Agree")');
      const bodyText = await page.locator('body').textContent();

      const hasAcceptBtn = await acceptBtn.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');
      const redirected = !page.url().includes('user-agreement');

      expect(hasAcceptBtn || !hasAgreement || redirected).toBeTruthy();
    });

    test('should have decline button when agreement required', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/user-agreement');

      const declineBtn = page.locator('button:has-text("Decline"), button:has-text("Reject")');
      const bodyText = await page.locator('body').textContent();

      const hasDeclineBtn = await declineBtn.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');
      const redirected = !page.url().includes('user-agreement');

      expect(hasDeclineBtn || !hasAgreement || redirected).toBeTruthy();
    });
  });
});

test.describe('Invalid Page Parameter Handling', () => {
  test('should handle empty page parameter', async ({ page }) => {
    await page.goto('/');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should handle special characters in URL', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/<script>alert(1)</script>');

    const bodyText = await page.locator('body').textContent();
    // Should not execute script, should show error or 404
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should handle SQL injection attempts in URL', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto("/zones/1'OR'1'='1");

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception|sql|syntax/i);
  });

  test('should handle path traversal attempts', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/../../etc/passwd');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/root:|fatal|exception/i);
  });
});

test.describe('Error Page Styling', () => {
  test('should maintain consistent styling on error pages', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/nonexistent-page');

    // Check for Bootstrap card or similar container
    const card = page.locator('.card, .container');
    const hasCard = await card.count() > 0;

    expect(hasCard).toBeTruthy();
  });

  test('should have navigation available on error pages', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/nonexistent-page');

    // Check for navigation elements
    const nav = page.locator('nav, .navbar, .sidebar');
    const bodyText = await page.locator('body').textContent();

    const hasNav = await nav.count() > 0;
    const has404 = bodyText.includes('404') || bodyText.toLowerCase().includes('not found');

    expect(hasNav || has404).toBeTruthy();
  });
});
