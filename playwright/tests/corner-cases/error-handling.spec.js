import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

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
      await page.waitForLoadState('networkidle');

      // Should redirect to login or show error
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const redirectedToLogin = url.includes('login') ||
                                 bodyText.toLowerCase().includes('login') ||
                                 bodyText.toLowerCase().includes('sign in');
      expect(redirectedToLogin).toBeTruthy();
    });

    test('should prevent CSRF attacks with token validation', async ({ page }) => {
      // Navigate to add zone page directly
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      // A missing or blank token is itself the regression this test guards against
      const csrfField = page.locator('input[name="_token"]');
      await expect(csrfField).toHaveCount(1);
      await expect(csrfField).not.toHaveValue('');

      await csrfField.evaluate((el) => el.value = 'invalid-token');

      const zoneName = `csrf-test-${Date.now()}.com`;
      const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"]').first();
      await expect(zoneInput).toBeVisible();
      await zoneInput.fill(zoneName);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      // The tampered token must be rejected outright, so the zone must not exist
      await page.goto('/zones/forward?letter=all');
      await expect(page.locator(`tr:has-text("${zoneName}")`)).toHaveCount(0);
    });
  });

  test.describe('Concurrent Actions', () => {
    test('should handle rapid sequential form submissions', async ({ page }) => {
      const testZone = `concurrent-test-${Date.now()}.com`;

      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"]');
      await expect(zoneInput).toBeVisible();

      await zoneInput.fill(testZone);

      // Attempt to click submit button quickly
      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      let zoneId;
      try {
        await page.waitForLoadState('networkidle');

        // Check we end up on a valid page - no crashes
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

        zoneId = await findZoneIdByName(page, testZone);
        expect(zoneId, `zone ${testZone} must be created`).toBeTruthy();
      } finally {
        // Also clean up when an assertion above failed
        zoneId ??= await findZoneIdByName(page, testZone).catch(() => null);
        if (zoneId) {
          await deleteZoneById(page, zoneId);
        }
      }
    });
  });

  test.describe('Pagination Edge Cases', () => {
    test('should handle navigation to non-existent pages', async ({ page }) => {
      // Try to access an invalid page number
      await page.goto('/zones/forward?letter=all&start=9999');
      await page.waitForLoadState('networkidle');

      // Should show zones list or empty state without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(bodyText.toLowerCase()).toMatch(/zone|no.*zone|empty/i);
    });
  });

  test.describe('Browser Navigation', () => {
    test('should handle browser back button correctly', async ({ page }) => {
      const testZone = `navigation-test-${Date.now()}.com`;

      // Navigate to add zone page
      await page.goto('/zones/add/master');
      await page.waitForLoadState('networkidle');

      const zoneInput = page.locator('[data-testid="zone-name-input"]');
      await expect(zoneInput).toBeVisible();

      // Fill out the form
      await zoneInput.fill(testZone);

      const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      let zoneId;
      try {
        await page.waitForLoadState('networkidle');

        // Try back button with error handling
        try {
          await page.goBack({ timeout: 5000 });
          await page.waitForLoadState('networkidle');
        } catch {
          // Back navigation may fail due to form POST
          await page.goto('/zones/add/master');
          await page.waitForLoadState('networkidle');
        }

        // Check page state
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);

        zoneId = await findZoneIdByName(page, testZone);
        expect(zoneId, `zone ${testZone} must be created`).toBeTruthy();
      } finally {
        // Also clean up when an assertion above failed
        zoneId ??= await findZoneIdByName(page, testZone).catch(() => null);
        if (zoneId) {
          await deleteZoneById(page, zoneId);
        }
      }
    });
  });

  test.describe('Direct URL Access', () => {
    test('should handle direct access to edit pages with invalid IDs', async ({ page }) => {
      // Try to access a non-existent record
      await page.goto('/zones/1/records/999999999/edit');
      await page.waitForLoadState('networkidle');

      // Should show error, not found, or redirect - just not crash
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should prevent unauthorized access to admin functions', async ({ page }) => {
      // First logout
      await page.goto('/logout');
      await page.waitForLoadState('networkidle');

      // Try to access admin page directly
      await page.goto('/users');
      await page.waitForLoadState('networkidle');

      // Should redirect to login or show access denied
      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const isProtected = url.includes('login') ||
                          bodyText.toLowerCase().includes('login') ||
                          bodyText.toLowerCase().includes('sign in') ||
                          bodyText.toLowerCase().includes('denied');
      expect(isProtected).toBeTruthy();
    });
  });

  test.describe('Special Characters Handling', () => {
    test('should properly escape HTML in user input display', async ({ page }) => {
      const testZone = `special-char-${Date.now()}.com`;
      const payload = '"<script>alert(1)</script>"';

      await page.goto('/zones/add/master');
      await page.locator('[data-testid="zone-name-input"]').fill(testZone);
      await page.locator('[data-testid="add-zone-button"]').click();

      const zoneId = await findZoneIdByName(page, testZone);
      expect(zoneId, `zone ${testZone} should exist after creation`).not.toBeNull();

      try {
        await page.goto(`/zones/${zoneId}/records/add`);
        await page.locator('select[name="records[0][type]"]').selectOption('TXT');
        await page.locator('input[name="records[0][name]"]').fill('html-test');
        await page.locator('input[name="records[0][content]"]').fill(payload);
        await page.locator('button[type="submit"][name="commit"]').click();

        await page.goto(`/zones/${zoneId}/edit`);

        const contents = await page.locator('input[name$="[content]"]').evaluateAll(
          (inputs) => inputs.map((input) => input.value)
        );
        expect(contents).toContain(payload);

        // The payload must come back as inert text, never as a live script node
        const executed = await page.locator('script').evaluateAll(
          (nodes) => nodes.some((node) => node.textContent.includes('alert(1)'))
        );
        expect(executed, 'the TXT payload must not be injected as a script').toBe(false);
      } finally {
        await deleteZoneById(page, zoneId);
      }
    });
  });
});
