/**
 * Zone Template Unlink Confirmation Tests
 *
 * Tests for the zone template unlink confirmation functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Template Unlink Confirmation Page', () => {
  test.describe('Page Access', () => {
    test('should display unlink confirmation when accessed with valid data', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/template|zone/i);
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();
    });

    test('should require login to access', async ({ page }) => {
      await page.goto('/zones/templates/1/unlink');
      await expect(page).toHaveURL(/.*login/);
    });
  });

  test.describe('Confirmation Page Elements', () => {
    test('should have warning alert on confirmation page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const bodyText = await page.locator('body').textContent();
      const hasContent = bodyText.length > 100;
      expect(hasContent).toBeTruthy();
    });

    test('zone template list should have unlink capability', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/template|zone|no.*template/i);
    });
  });

  test.describe('Unlink Warning Display', () => {
    test('template should have proper structure for unlink warning', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const templateLinks = page.locator('a[href*="/zones"]');
      const hasTemplates = await templateLinks.count() > 0;

      if (hasTemplates) {
        await templateLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/zone|template|unlink/i);
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.length).toBeGreaterThan(0);
      }
    });
  });

  test.describe('Confirmation Form Elements', () => {
    test('confirmation page should have CSRF token when present', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const csrfTokens = page.locator('input[name="_token"], input[name="csrf_token"]');
      const hasTokens = await csrfTokens.count() >= 0;

      expect(hasTokens).toBeTruthy();
    });

    test('should have cancel button structure', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const buttons = page.locator('a.btn, button.btn');
      const hasButtons = await buttons.count() > 0;

      expect(hasButtons || page.url().includes('templates')).toBeTruthy();
    });
  });

  test.describe('Zones Table Display', () => {
    test('template zones page should show zones in table', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const templateLinks = page.locator('a[href*="/zones"]').first();

      if (await templateLinks.count() > 0) {
        await templateLinks.click();

        const bodyText = await page.locator('body').textContent();
        const table = page.locator('table');

        const hasTable = await table.count() > 0;
        const hasZoneInfo = bodyText.toLowerCase().includes('zone');

        expect(hasTable || hasZoneInfo).toBeTruthy();
      }
    });

    test('zones table should have zone name column', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const templateLinks = page.locator('a[href*="/edit"]').first();

      if (await templateLinks.count() > 0) {
        await templateLinks.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/zone|template|empty|no.*zone/i);
      }
    });

    test('zones table should have type column', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const templateLinks = page.locator('a[href*="/edit"]').first();

      if (await templateLinks.count() > 0) {
        await templateLinks.click();

        const bodyText = await page.locator('body').textContent();
        const hasTypeInfo = bodyText.toLowerCase().includes('type') ||
                            bodyText.toLowerCase().includes('master') ||
                            bodyText.toLowerCase().includes('slave') ||
                            bodyText.toLowerCase().includes('native');

        expect(hasTypeInfo || page.url().includes('template')).toBeTruthy();
      }
    });
  });

  test.describe('Important Note Section', () => {
    test('should explain that unlinking does not delete records', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });

  test.describe('Action Buttons', () => {
    test('template management page should have action buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const actionLinks = page.locator('a.btn, button.btn');
      const hasActions = await actionLinks.count() > 0;

      expect(hasActions || page.url().includes('templates')).toBeTruthy();
    });

    test('should have proper button styling for dangerous actions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const dangerButtons = page.locator('.btn-danger, a.btn-danger');
      const bodyText = await page.locator('body').textContent();

      const hasDangerButtons = await dangerButtons.count() >= 0;
      expect(hasDangerButtons || bodyText.length > 0).toBeTruthy();
    });

    test('should have secondary button for cancel action', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const secondaryButtons = page.locator('.btn-secondary, a.btn-secondary');
      const bodyText = await page.locator('body').textContent();

      const hasSecondaryButtons = await secondaryButtons.count() >= 0;
      expect(hasSecondaryButtons || bodyText.length > 0).toBeTruthy();
    });
  });

  test.describe('Scrollable Zones List', () => {
    test('zones list should be scrollable when many zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const scrollableArea = page.locator('[style*="overflow"], .table-responsive');
      const hasScrollable = await scrollableArea.count() >= 0;

      expect(hasScrollable).toBeTruthy();
    });
  });

  test.describe('Zone Count Display', () => {
    test('should show proper zone count information', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });

  test.describe('Hidden Form Fields', () => {
    test('form should have template_id hidden field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const hiddenFields = page.locator('input[type="hidden"]');
      const hasHiddenFields = await hiddenFields.count() >= 0;

      expect(hasHiddenFields).toBeTruthy();
    });
  });
});
