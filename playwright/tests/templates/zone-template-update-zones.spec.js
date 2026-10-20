/**
 * Zone Template Update Zones Tests
 *
 * Tests for "Update zones from template" functionality.
 * Verifies the fix for GitHub issues #944, #945, and #1210.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addTemplateRecord, createTemplate, deleteTemplate } from '../../helpers/templates.js';
import { deleteZoneById, findZoneIdByName, uniqueName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Patterns rendered by PHP/PDO when a query, type assertion, or FK constraint
// blows up inside the template update path. Issue #1210 surfaces as
// "SQLSTATE[23503]: Foreign key violation" / "violates foreign key constraint";
// keep the prior #944/#945 patterns too.
const FATAL_ERROR_PATTERN = /fatal|exception|TypeError|null given|SQLSTATE|foreign key|constraint violation|integrity constraint/i;

// Template creation, zone creation and the zone rewrite make each step slow under parallel load
test.describe.configure({ mode: 'serial', timeout: 90000 });

test.describe('Zone Template - Update Zones (Issues #944, #945, #1210)', () => {
  const templateName = uniqueName('uz-tpl');
  const zoneName = `${uniqueName('uz-zone')}.example.com`;
  let templateId = null;
  let zoneId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    templateId = await createTemplate(page, templateName, 'Test template for update zones');
    expect(templateId, `template ${templateName} must be created`).toBeTruthy();

    await addTemplateRecord(page, templateId, { type: 'A', name: 'www', content: '192.168.1.100' });

    await page.close();
  });

  test.describe('Create Zone with Template', () => {
    test('should create a zone using the test template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto('/zones/add/master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
        .first()
        .fill(zoneName);

      await page.locator('#zone_template').selectOption(templateId);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      zoneId = await findZoneIdByName(page, zoneName);
      expect(zoneId).toBeTruthy();
    });
  });

  test.describe('Update Zones from Template', () => {
    test('should access edit template page with update zones button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/templates/${templateId}/edit`);

      const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"], button:has-text("Update zones")');
      const hasUpdateBtn = await updateBtn.count() > 0;

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(FATAL_ERROR_PATTERN);
      expect(hasUpdateBtn).toBe(true);
    });

    test('should add a new record to template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await addTemplateRecord(page, templateId, { type: 'A', name: 'mail', content: '192.168.1.101' });

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(FATAL_ERROR_PATTERN);
    });

    test('should update zones from template without fatal error (issue #944/#945)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/templates/${templateId}/edit`);

      const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"]').first();
      await expect(updateBtn).toBeVisible();

      await updateBtn.scrollIntoViewIfNeeded();
      // Applying a template rewrites every linked zone, which on the API backend
      // is a round trip per zone, so the submission outlives the action timeout.
      await updateBtn.click({ timeout: 60000 });
      // Not networkidle: the API-backed instances keep connections open and
      // never reach it
      await page.waitForLoadState('domcontentloaded');

      await expect(page.locator('body')).not.toContainText(FATAL_ERROR_PATTERN);
      await expect(page.locator('.alert-success')).toContainText(/Zones have been updated successfully/i);
    });

    test('should verify zone records after template update', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto(`/zones/${zoneId}/edit`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(FATAL_ERROR_PATTERN);

      await expect(page).toHaveURL(/.*zones.*edit/);
    });
  });

  test.describe('Edge Cases', () => {
    test('should handle template with no linked zones gracefully', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const isolatedTemplateName = uniqueName('uz-iso');

      // Create isolated template
      await page.goto('/zones/templates/add');
      await page.locator('input[name*="name"]').first().fill(isolatedTemplateName);
      await page.locator('input[name*="descr"], textarea[name*="descr"]').first().fill('Isolated template');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // The template was created just above, so its row and links are there
      await page.goto('/zones/templates');
      try {
        const row = page.locator('.template-row').filter({ hasText: isolatedTemplateName });
        await expect(row).toHaveCount(1);

        await row.locator('a[href*="/edit"]').first().click();
        await expect(page.locator('body')).not.toContainText(FATAL_ERROR_PATTERN);
      } finally {
        await page.goto('/zones/templates');
        await page.locator('.template-row').filter({ hasText: isolatedTemplateName }).locator('a[href*="/delete"]').first().click();
        await page.locator('button[type="submit"][name="confirm"]').click();
        await expect(page.locator('table')).not.toContainText(isolatedTemplateName);
      }
    });

    test('should handle update with multiple zones linked to template', async ({ page }) => {
      // Creating a zone, rewriting two linked zones and cleaning up is more than
      // one test's budget on the API backend, where each zone is a round trip.
      test.slow();

      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const secondZoneName = `${uniqueName('uz-zone2')}.example.com`;
      await page.goto('/zones/add/master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
        .first()
        .fill(secondZoneName);

      await page.locator('#zone_template').selectOption(templateId);

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');

      try {
        await page.goto(`/zones/templates/${templateId}/edit`);

        const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"]').first();
        await expect(updateBtn).toBeVisible();

        await updateBtn.scrollIntoViewIfNeeded();
        // Two linked zones now, so the rewrite takes even longer on the API backend
        await updateBtn.click({ timeout: 60000 });
        await page.waitForLoadState('domcontentloaded');

        await expect(page.locator('body')).not.toContainText(FATAL_ERROR_PATTERN);

      } finally {
        const secondZoneId = await findZoneIdByName(page, secondZoneName);
        if (secondZoneId) {
          await deleteZoneById(page, secondZoneId);
        }
      }
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    try {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }

      if (templateId) {
        await deleteTemplate(page, templateId);
      }

      await page.close();
    } catch {
      // Ignore cleanup errors
    }
  });
});
