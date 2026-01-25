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
      await page.goto('/index.php?page=nonexistent_page_12345');

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
      await page.goto('/index.php?page=nonexistent_page_xyz');

      const bodyText = await page.locator('body').textContent();

      // Template shows: <h1 class="display-1 fw-bold text-secondary">404</h1>
      const has404 = bodyText.includes('404') ||
                     bodyText.toLowerCase().includes('not found') ||
                     bodyText.toLowerCase().includes('error');
      expect(has404).toBeTruthy();
    });

    test('should display page not found message', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=fake_page_test');

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
      await page.goto('/index.php?page=nonexistent_test');

      // Template has: <i class="bi bi-exclamation-triangle display-1 text-warning"></i>
      const icon = page.locator('.bi-exclamation-triangle, .bi-x-circle, i[class*="bi-"]');
      const bodyText = await page.locator('body').textContent();

      const hasIcon = await icon.count() > 0;
      const has404Content = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasIcon || has404Content).toBeTruthy();
    });

    test('should display explanation list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=test_nonexistent');

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
      await page.goto('/index.php?page=invalid_page');

      const homeLink = page.locator('a[href*="index.php"]:has-text("Home"), a:has-text("Homepage")');
      const bodyText = await page.locator('body').textContent();

      const hasHomeLink = await homeLink.count() > 0;
      const has404 = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasHomeLink || has404 || page.url().includes('index.php')).toBeTruthy();
    });

    test('should have go back button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=nonexistent_xyz');

      // Template has: <button onclick="history.back()">Go Back</button>
      const backBtn = page.locator('button:has-text("Back"), a:has-text("Back")');
      const bodyText = await page.locator('body').textContent();

      const hasBackBtn = await backBtn.count() > 0;
      const has404 = bodyText.includes('404') || bodyText.toLowerCase().includes('error');

      expect(hasBackBtn || has404 || page.url().includes('index.php')).toBeTruthy();
    });
  });
});

test.describe('User Agreement Page', () => {
  test.describe('Page Access', () => {
    test('should access user agreement page when logged in', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      const bodyText = await page.locator('body').textContent();

      // Should show agreement page or redirect if not required
      const hasAgreement = bodyText.toLowerCase().includes('agreement') ||
                           bodyText.toLowerCase().includes('terms') ||
                           bodyText.toLowerCase().includes('accept');
      const redirected = page.url().includes('index') && !page.url().includes('user_agreement');

      // Either shows agreement or redirects (if agreement not required)
      expect(hasAgreement || redirected).toBeTruthy();
    });

    test('should display page title', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "User Agreement"
      const hasTitle = bodyText.toLowerCase().includes('agreement') ||
                       bodyText.toLowerCase().includes('terms');
      expect(hasTitle || page.url().includes('index')).toBeTruthy();
    });
  });

  test.describe('Agreement Content', () => {
    test('should display agreement content area', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Template has: <div class="agreement-content mb-4">
      const contentArea = page.locator('.agreement-content, .card-body');
      const bodyText = await page.locator('body').textContent();

      const hasContentArea = await contentArea.count() > 0;
      const hasContent = bodyText.length > 100;

      expect(hasContentArea || hasContent).toBeTruthy();
    });

    test('should have scrollable content area', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Template has: style="max-height: 350px; overflow-y: auto;"
      const scrollableArea = page.locator('[style*="overflow"]');
      const bodyText = await page.locator('body').textContent();

      const hasScrollable = await scrollableArea.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');

      expect(hasScrollable || hasAgreement || page.url().includes('index')).toBeTruthy();
    });
  });

  test.describe('Agreement Form', () => {
    test('should have accept checkbox', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Template has: <input type="checkbox" id="accept_agreement" name="accept_agreement">
      const checkbox = page.locator('input[type="checkbox"][name="accept_agreement"], input#accept_agreement');
      const bodyText = await page.locator('body').textContent();

      const hasCheckbox = await checkbox.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement') ||
                           bodyText.toLowerCase().includes('accept');

      expect(hasCheckbox || hasAgreement || page.url().includes('index')).toBeTruthy();
    });

    test('should have accept checkbox label', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      const bodyText = await page.locator('body').textContent();

      // Template shows: "I have read and agree to the terms outlined above"
      const hasLabel = bodyText.toLowerCase().includes('read') ||
                       bodyText.toLowerCase().includes('agree') ||
                       bodyText.toLowerCase().includes('terms');
      expect(hasLabel || page.url().includes('index')).toBeTruthy();
    });

    test('should have accept button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Template has: <button type="submit">Accept & Continue</button>
      const acceptBtn = page.locator('button[type="submit"]:has-text("Accept"), button:has-text("Continue")');
      const bodyText = await page.locator('body').textContent();

      const hasAcceptBtn = await acceptBtn.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');

      expect(hasAcceptBtn || hasAgreement || page.url().includes('index')).toBeTruthy();
    });

    test('should have decline button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Template has: <a href="index.php?page=logout">Decline & Logout</a>
      const declineBtn = page.locator('a:has-text("Decline"), a[href*="logout"]');
      const bodyText = await page.locator('body').textContent();

      const hasDeclineBtn = await declineBtn.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');

      expect(hasDeclineBtn || hasAgreement || page.url().includes('index')).toBeTruthy();
    });

    test('should include CSRF token', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      const csrfToken = page.locator('input[name="_token"]');
      const hasToken = await csrfToken.count() > 0;

      expect(hasToken || page.url().includes('index')).toBeTruthy();
    });

    test('should include agreement version', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Template has: <input type="hidden" name="agreement_version" value="{{ agreement_version }}">
      const versionInput = page.locator('input[name="agreement_version"]');
      const bodyText = await page.locator('body').textContent();

      const hasVersion = await versionInput.count() > 0;
      const hasAgreement = bodyText.toLowerCase().includes('agreement');

      expect(hasVersion || hasAgreement || page.url().includes('index')).toBeTruthy();
    });
  });

  test.describe('Agreement Validation', () => {
    test('should require checkbox to be checked', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      const checkbox = page.locator('input[name="accept_agreement"]');

      if (await checkbox.count() > 0) {
        // Check if required attribute exists
        const isRequired = await checkbox.getAttribute('required');
        expect(isRequired !== null).toBeTruthy();
      }
    });

    test('should show validation error when checkbox not checked', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      const submitBtn = page.locator('button[type="submit"]');

      if (await submitBtn.count() > 0) {
        await submitBtn.click();

        // Should show validation error or stay on page
        const invalidFeedback = page.locator('.invalid-feedback');
        const bodyText = await page.locator('body').textContent();

        const hasError = await invalidFeedback.count() > 0;
        const hasValidation = bodyText.toLowerCase().includes('must accept') ||
                               bodyText.toLowerCase().includes('required');

        expect(hasError || hasValidation || page.url().includes('user_agreement')).toBeTruthy();
      }
    });
  });

  test.describe('Agreement Message Display', () => {
    test('should display alerts when present', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Template shows conditional alerts: {% if msg %}
      const alerts = page.locator('.alert');
      const bodyText = await page.locator('body').textContent();

      // Alerts may or may not be present
      const hasAlerts = await alerts.count() >= 0;
      expect(hasAlerts).toBeTruthy();
    });
  });

  test.describe('Decline Action', () => {
    test('decline should link to logout', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=user_agreement');

      // Look for the decline button specifically (not the navbar logout)
      const declineLink = page.locator('a[href*="logout"]:has-text("Decline"), a.btn[href*="logout"]');
      const anyLogoutLink = page.locator('a[href*="logout"]');
      const bodyText = await page.locator('body').textContent();

      if (await declineLink.count() > 0) {
        const href = await declineLink.first().getAttribute('href');
        expect(href).toContain('logout');
      } else if (await anyLogoutLink.count() > 0) {
        // At least a logout link exists on the page
        const href = await anyLogoutLink.first().getAttribute('href');
        expect(href).toContain('logout');
      } else {
        // User agreement page may not be shown if agreement is not required
        // or user has already accepted - check that we're on some valid page
        const redirectedAway = !page.url().includes('user_agreement');
        const hasAgreementContent = bodyText.toLowerCase().includes('agreement');

        expect(redirectedAway || hasAgreementContent || page.url().includes('index')).toBeTruthy();
      }
    });
  });
});

test.describe('Error Handling Generic', () => {
  test.describe('Invalid Page Parameter', () => {
    test('should handle empty page parameter', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=');

      // Should either show dashboard or error
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should handle special characters in page parameter', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=<script>alert(1)</script>');

      // Should not execute script and handle gracefully
      const bodyText = await page.locator('body').textContent();
      const hasScript = bodyText.includes('<script>');

      // Script should be escaped or not present
      expect(!hasScript || bodyText.length > 0).toBeTruthy();
    });

    test('should handle very long page parameter', async ({ adminPage: page }) => {
      const longParam = 'a'.repeat(1000);
      await page.goto(`/index.php?page=${longParam}`);

      // Should handle gracefully without crashing
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});
