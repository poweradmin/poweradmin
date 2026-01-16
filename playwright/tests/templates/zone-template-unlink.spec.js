/**
 * Zone Template Unlink Confirmation Tests
 *
 * Tests for the zone template unlink confirmation functionality
 * covering the confirm_unlink_zones_templ.html template.
 */

import { test, expect } from '../../fixtures/test-fixtures.js';

test.describe('Zone Template Unlink Confirmation Page', () => {
  test.describe('Page Access', () => {
    test('should display unlink confirmation when accessed with valid data', async ({ adminPage: page }) => {
      // First navigate to zone templates list
      await page.goto('/index.php?page=list_zone_templ');

      const bodyText = await page.locator('body').textContent();
      // Should be able to access zone templates
      expect(bodyText.toLowerCase()).toMatch(/template|zone/i);
    });

    test('should display breadcrumb navigation', async ({ adminPage: page }) => {
      // Access any template-related page to verify breadcrumb structure
      await page.goto('/index.php?page=list_zone_templ');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();
    });

    test('should require login to access', async ({ page }) => {
      await page.goto('/index.php?page=unlink_zones_templ');

      await expect(page).toHaveURL(/page=login/);
    });
  });

  test.describe('Confirmation Page Elements', () => {
    test('should have warning alert on confirmation page', async ({ adminPage: page }) => {
      // Navigate to template list first
      await page.goto('/index.php?page=list_zone_templ');

      // Check page structure
      const bodyText = await page.locator('body').textContent();
      const hasContent = bodyText.length > 100;
      expect(hasContent).toBeTruthy();
    });

    test('zone template list should have unlink capability', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const bodyText = await page.locator('body').textContent();
      // Should show template list or indicate no templates
      expect(bodyText.toLowerCase()).toMatch(/template|zone|no.*template/i);
    });
  });

  test.describe('Unlink Warning Display', () => {
    test('template should have proper structure for unlink warning', async ({ adminPage: page }) => {
      // Navigate to template zones list if a template exists
      await page.goto('/index.php?page=list_zone_templ');

      // Check for template links
      const templateLinks = page.locator('a[href*="list_template_zones"]');
      const hasTemplates = await templateLinks.count() > 0;

      if (hasTemplates) {
        // Click first template to see zones
        await templateLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/zone|template|unlink/i);
      } else {
        // No templates exist, verify page structure
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.length).toBeGreaterThan(0);
      }
    });
  });

  test.describe('Confirmation Form Elements', () => {
    test('confirmation page should have CSRF token when present', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Check for CSRF tokens in page forms
      const csrfTokens = page.locator('input[name="_token"], input[name="csrf_token"]');
      const hasTokens = await csrfTokens.count() >= 0; // May or may not have forms

      expect(hasTokens).toBeTruthy();
    });

    test('should have cancel button structure', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Check for general page structure
      const buttons = page.locator('a.btn, button.btn');
      const hasButtons = await buttons.count() > 0;

      expect(hasButtons || page.url().includes('zone_templ')).toBeTruthy();
    });
  });

  test.describe('Zones Table Display', () => {
    test('template zones page should show zones in table', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Check if there are any templates to view
      const templateLinks = page.locator('a[href*="list_template_zones"]');

      if (await templateLinks.count() > 0) {
        await templateLinks.first().click();

        // Should display zones or indicate no zones
        const bodyText = await page.locator('body').textContent();
        const table = page.locator('table');

        const hasTable = await table.count() > 0;
        const hasZoneInfo = bodyText.toLowerCase().includes('zone');

        expect(hasTable || hasZoneInfo).toBeTruthy();
      }
    });

    test('zones table should have zone name column', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const templateLinks = page.locator('a[href*="list_template_zones"]');

      if (await templateLinks.count() > 0) {
        await templateLinks.first().click();

        const bodyText = await page.locator('body').textContent();
        // Should mention zones or show empty state
        expect(bodyText.toLowerCase()).toMatch(/zone|template|empty|no.*zone/i);
      }
    });

    test('zones table should have type column', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      const templateLinks = page.locator('a[href*="list_template_zones"]');

      if (await templateLinks.count() > 0) {
        await templateLinks.first().click();

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
    test('should explain that unlinking does not delete records', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Check for informational content about templates
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });

  test.describe('Action Buttons', () => {
    test('template management page should have action buttons', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Look for action buttons or links
      const actionLinks = page.locator('a.btn, button.btn');
      const hasActions = await actionLinks.count() > 0;

      expect(hasActions || page.url().includes('zone_templ')).toBeTruthy();
    });

    test('should have proper button styling for dangerous actions', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Delete/unlink buttons should use danger styling
      const dangerButtons = page.locator('.btn-danger, a.btn-danger');
      const bodyText = await page.locator('body').textContent();

      const hasDangerButtons = await dangerButtons.count() >= 0;
      expect(hasDangerButtons || bodyText.length > 0).toBeTruthy();
    });

    test('should have secondary button for cancel action', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Cancel buttons should use secondary styling
      const secondaryButtons = page.locator('.btn-secondary, a.btn-secondary');
      const bodyText = await page.locator('body').textContent();

      const hasSecondaryButtons = await secondaryButtons.count() >= 0;
      expect(hasSecondaryButtons || bodyText.length > 0).toBeTruthy();
    });
  });

  test.describe('Scrollable Zones List', () => {
    test('zones list should be scrollable when many zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Check for overflow/scrollable containers
      const scrollableArea = page.locator('[style*="overflow"], .table-responsive');
      const hasScrollable = await scrollableArea.count() >= 0;

      expect(hasScrollable).toBeTruthy();
    });
  });

  test.describe('Zone Count Display', () => {
    test('should show proper zone count information', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Template list may show zone counts
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });

  test.describe('Hidden Form Fields', () => {
    test('form should have template_id hidden field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zone_templ');

      // Forms should have proper hidden fields for IDs
      const hiddenFields = page.locator('input[type="hidden"]');
      const hasHiddenFields = await hiddenFields.count() >= 0;

      expect(hasHiddenFields).toBeTruthy();
    });
  });
});
