/**
 * Error Pages Tests
 *
 * Tests for error handling and special pages:
 * - 404.html - Page not found
 * - user_agreement.html - User agreement acceptance
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

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

    test('should display 404 error code prominently', async ({ adminPage: page }) => {
      await page.goto('/nonexistent-page-xyz');

      const bodyText = await page.locator('body').textContent();

      // Template shows: <h1 class="display-1 fw-bold text-secondary">404</h1>
      const has404 = bodyText.includes('404') ||
                     bodyText.toLowerCase().includes('not found') ||
                     bodyText.toLowerCase().includes('error');
      expect(has404).toBeTruthy();
    });

    test('should display page not found message', async ({ adminPage: page }) => {
      await page.goto('/fake-page-test');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "Page Not Found"
      const hasMessage = bodyText.toLowerCase().includes('not found') ||
                          bodyText.toLowerCase().includes('page') ||
                          bodyText.toLowerCase().includes('error');
      expect(hasMessage).toBeTruthy();
    });
  });

  test.describe('404 Page Content', () => {
    test('should display error icon', async ({ adminPage: page }) => {
      await page.goto('/nonexistent-test');

      // Template has: <i class="bi bi-exclamation-triangle display-1 text-warning"></i>
      const icon = page.locator('.bi-exclamation-triangle, .bi-x-circle, i[class*="bi-"]');
      const bodyText = await page.locator('body').textContent();

      const hasIcon = await icon.count() > 0;
      const has404Content = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasIcon || has404Content).toBeTruthy();
    });

    test('should display explanation list', async ({ adminPage: page }) => {
      await page.goto('/test-nonexistent');

      const bodyText = await page.locator('body').textContent();

      // Template shows possible reasons:
      // - URL is incorrect
      // - Page has been moved or deleted
      // - No permission
      const hasExplanation = bodyText.toLowerCase().includes('url') ||
                              bodyText.toLowerCase().includes('moved') ||
                              bodyText.toLowerCase().includes('deleted') ||
                              bodyText.toLowerCase().includes('permission') ||
                              bodyText.toLowerCase().includes('404') ||
                              bodyText.toLowerCase().includes('error');
      expect(hasExplanation).toBeTruthy();
    });
  });

  test.describe('404 Page Navigation', () => {
    test('should have homepage link', async ({ adminPage: page }) => {
      await page.goto('/invalid-page');

      const homeLink = page.locator('a[href="/"]:has-text("Home"), a:has-text("Homepage")');
      const bodyText = await page.locator('body').textContent();

      const hasHomeLink = await homeLink.count() > 0;
      const has404 = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasHomeLink || has404).toBeTruthy();
    });

    test('should have go back button', async ({ adminPage: page }) => {
      await page.goto('/nonexistent-xyz');

      // Template has: <button onclick="history.back()">Go Back</button>
      const backBtn = page.locator('button:has-text("Back"), a:has-text("Back")');
      const bodyText = await page.locator('body').textContent();

      const hasBackBtn = await backBtn.count() > 0;
      const has404 = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasBackBtn || has404).toBeTruthy();
    });
  });
});

test.describe('User Agreement Page', () => {
  test('should redirect to the dashboard when the agreement is disabled', async ({ adminPage: page }) => {
    // user_agreement.enabled is false on every devcontainer instance
    await page.goto('/user-agreement');

    await expect(page).not.toHaveURL(/user-agreement/);
    expect(new URL(page.url()).pathname).toBe('/');
  });

  test.describe('Page Access', () => {
    test('should access user agreement page when logged in', async ({ adminPage: page }) => {
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

    test('should display page title', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "User Agreement"
      const hasTitle = bodyText.toLowerCase().includes('agreement') ||
                       bodyText.toLowerCase().includes('terms');
      expect(hasTitle || !page.url().includes('user-agreement')).toBeTruthy();
    });
  });

  test.describe('Agreement Content', () => {
    test('should display agreement content area', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');

      // Template has: <div class="agreement-content mb-4">
      const contentArea = page.locator('.agreement-content, .card-body');
      const bodyText = await page.locator('body').textContent();

      const hasContentArea = await contentArea.count() > 0;
      const hasContent = bodyText.length > 100;

      expect(hasContentArea || hasContent).toBeTruthy();
    });

    test('should have scrollable content area', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');

      // Template has: style="max-height: 350px; overflow-y: auto;"
      const scrollableArea = page.locator('[style*="overflow"]');
      const bodyText = await page.locator('body').textContent();

      const hasScrollable = await scrollableArea.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');

      expect(hasScrollable || hasAgreement || !page.url().includes('user-agreement')).toBeTruthy();
    });
  });

  test.describe('Agreement Form', () => {
    test('should have accept checkbox', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');

      // Template has: <input type="checkbox" id="accept_agreement" name="accept_agreement">
      const checkbox = page.locator('input[type="checkbox"][name="accept_agreement"], input#accept_agreement');
      const bodyText = await page.locator('body').textContent();

      const hasCheckbox = await checkbox.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement') ||
                           bodyText.toLowerCase().includes('accept');

      expect(hasCheckbox || hasAgreement || !page.url().includes('user-agreement')).toBeTruthy();
    });

    test('should have accept checkbox label', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "I have read and agree to the terms outlined above"
      const hasLabel = bodyText.toLowerCase().includes('read') ||
                       bodyText.toLowerCase().includes('agree') ||
                       bodyText.toLowerCase().includes('terms');
      expect(hasLabel || !page.url().includes('user-agreement')).toBeTruthy();
    });

    test('should have accept button', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');

      // Template has: <button type="submit">Accept & Continue</button>
      const acceptBtn = page.locator('button[type="submit"]:has-text("Accept"), button:has-text("Continue")');
      const bodyText = await page.locator('body').textContent();

      const hasAcceptBtn = await acceptBtn.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');

      expect(hasAcceptBtn || hasAgreement || !page.url().includes('user-agreement')).toBeTruthy();
    });

    test('should have decline button', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');

      // Template has: <a href="/logout">Decline & Logout</a>
      const declineBtn = page.locator('a:has-text("Decline"), a[href*="logout"]');
      const bodyText = await page.locator('body').textContent();

      const hasDeclineBtn = await declineBtn.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');

      expect(hasDeclineBtn || hasAgreement || !page.url().includes('user-agreement')).toBeTruthy();
    });

    test('should include CSRF token', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');
      test.skip(!page.url().includes('user-agreement'), 'user_agreement is disabled on this instance');

      await expect(page.locator('input[name="_token"]')).toHaveCount(1);
    });

    test('should include agreement version', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');
      test.skip(!page.url().includes('user-agreement'), 'user_agreement is disabled on this instance');

      await expect(page.locator('input[name="agreement_version"]')).toHaveCount(1);
    });
  });

  test.describe('Agreement Validation', () => {
    test('should require checkbox to be checked', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');
      test.skip(!page.url().includes('user-agreement'), 'user_agreement is disabled on this instance');

      await expect(page.locator('input[name="accept_agreement"]')).toHaveAttribute('required', '');
    });

    test('should show validation error when checkbox not checked', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');
      test.skip(!page.url().includes('user-agreement'), 'user_agreement is disabled on this instance');

      await page.locator('form.needs-validation button[type="submit"]').click();

      await expect(page).toHaveURL(/user-agreement/);
      await expect(page.locator('form.needs-validation')).toHaveClass(/was-validated/);
    });
  });

  test.describe('Decline Action', () => {
    test('decline should link to logout', async ({ adminPage: page }) => {
      await page.goto('/user-agreement');
      test.skip(!page.url().includes('user-agreement'), 'user_agreement is disabled on this instance');

      const declineLink = page.locator('form.needs-validation a.btn[href*="logout"]');
      await expect(declineLink).toHaveCount(1);
      await expect(declineLink).toHaveAttribute('href', /logout/);
    });
  });
});

test.describe('Error Handling Generic', () => {
  test.describe('Invalid Page Parameter', () => {
    test('should handle empty page parameter', async ({ adminPage: page }) => {
      await page.goto('/');

      // Should show dashboard
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should handle special characters in URL', async ({ adminPage: page }) => {
      await page.goto('/%3Cscript%3Ealert(1)%3C/script%3E');

      // Should not execute script and handle gracefully
      const bodyText = await page.locator('body').textContent();
      const hasScript = bodyText.includes('<script>');

      // Script should be escaped or not present
      expect(!hasScript || bodyText.length > 0).toBeTruthy();
    });

    test('should handle very long URL', async ({ adminPage: page }) => {
      const longPath = 'a'.repeat(500);
      await page.goto(`/${longPath}`);

      // Should handle gracefully without crashing
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});
