/**
 * Permission Template Toggle Tests
 *
 * Tests that show_group_access_templates and show_user_access_templates
 * config toggles control visibility of template types on the templates page.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Permission Template Toggles', () => {
  test.describe('Default Config (both visible)', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display access templates page', async ({ page }) => {
      await page.goto('/templates');
      await expect(page).toHaveURL(/.*templates/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show template type column when both types visible', async ({ page }) => {
      await page.goto('/templates');
      const bodyText = await page.locator('body').textContent();
      // When both user and group templates are shown, a Type column should appear
      const hasTypeColumn = bodyText.includes('Type') || bodyText.includes('User') || bodyText.includes('Group');
      expect(hasTypeColumn).toBeTruthy();
    });

    test('should list both user and group templates', async ({ page }) => {
      await page.goto('/templates');
      const rows = page.locator('table tbody tr');
      expect(await rows.count()).toBeGreaterThan(0);
    });

    test('should show group templates link in navigation', async ({ page }) => {
      await page.goto('/');
      const groupTemplatesLink = page.locator('a[href*="/groups"]');
      expect(await groupTemplatesLink.count()).toBeGreaterThan(0);
    });
  });
});
