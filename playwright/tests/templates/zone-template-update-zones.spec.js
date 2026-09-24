/**
 * Zone Template Update Zones Tests
 *
 * Tests for "Update zones from template" functionality.
 * Verifies the fix for GitHub issues #944, #945, and #1210.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Patterns rendered by PHP/PDO when a query, type assertion, or FK constraint
// blows up inside the template update path. Issue #1210 surfaces as
// "SQLSTATE[23503]: Foreign key violation" / "violates foreign key constraint";
// keep the prior #944/#945 patterns too.
const FATAL_ERROR_PATTERN = /fatal|exception|TypeError|null given|SQLSTATE|foreign key|constraint violation|integrity constraint/i;

test.describe.configure({ mode: 'serial' });

test.describe('Zone Template - Update Zones (Issues #944, #945, #1210)', () => {
  const templateName = `update-zones-test-${Date.now()}`;
  const zoneName = `update-test-${Date.now()}.example.com`;
  let templateId = null;
  let zoneId = null;

  // Helper to create template and get ID
  async function createTemplateAndGetId(page) {
    await page.goto('/zones/templates/add');
    await page.locator('input[name*="name"]').first().fill(templateName);
    await page.locator('input[name*="descr"], textarea[name*="descr"]').first().fill('Test template for update zones');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/templates');
    const row = page.locator(`tr:has-text("${templateName}")`);
    if (await row.count() > 0) {
      const editLink = row.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href.match(/\/zones\/templates\/(\d+)\/edit/);
        return match ? match[1] : null;
      }
    }
    return null;
  }

  // Helper to find zone ID by name
  async function findZoneIdByName(page, name) {
    await page.goto('/zones/forward?letter=all');
    const row = page.locator(`tr:has-text("${name}")`);
    if (await row.count() > 0) {
      const editLink = row.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href.match(/\/zones\/(\d+)\/edit/);
        return match ? match[1] : null;
      }
    }
    return null;
  }

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    templateId = await createTemplateAndGetId(page);

    if (templateId) {
      // Add a basic A record to the template
      await page.goto(`/zones/templates/${templateId}/records/add`);
      await page.locator('select[name*="type"]').first().selectOption('A');
      const nameField = page.locator('input[name*="name"]').first();
      if (await nameField.count() > 0) {
        await nameField.fill('www');
      }
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.100');
      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('3600');
      }
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');
    }

    await page.close();
  });

  test.describe('Create Zone with Template', () => {
    test('should create a zone using the test template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      test.skip(!templateId, 'Template was not created');

      await page.goto('/zones/add/master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
        .first()
        .fill(zoneName);

      const templateSelect = page.locator('select[name*="zone_templ"], select[name*="template"]').first();
      if (await templateSelect.count() > 0) {
        const options = await templateSelect.locator('option').all();
        for (const option of options) {
          const text = await option.textContent();
          if (text && text.includes(templateName)) {
            const value = await option.getAttribute('value');
            await templateSelect.selectOption(value);
            break;
          }
        }
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      zoneId = await findZoneIdByName(page, zoneName);
      expect(zoneId).toBeTruthy();
    });
  });

  test.describe('Update Zones from Template', () => {
    test('should access edit template page with update zones button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      test.skip(!templateId, 'Template was not created');

      await page.goto(`/zones/templates/${templateId}/edit`);

      const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"], button:has-text("Update zones")');
      const hasUpdateBtn = await updateBtn.count() > 0;

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(FATAL_ERROR_PATTERN);
      expect(hasUpdateBtn).toBe(true);
    });

    test('should add a new record to template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      test.skip(!templateId, 'Template was not created');

      await page.goto(`/zones/templates/${templateId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      const nameField = page.locator('input[name*="name"]').first();
      if (await nameField.count() > 0) {
        await nameField.fill('mail');
      }
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.101');
      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('3600');
      }
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).not.toContainText(FATAL_ERROR_PATTERN);
    });

    test('should update zones from template without fatal error (issue #944/#945)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      test.skip(!templateId, 'Template was not created');
      test.skip(!zoneId, 'Zone was not created');

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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(FATAL_ERROR_PATTERN);

      const hasSuccess = bodyText.toLowerCase().includes('success') ||
                        bodyText.toLowerCase().includes('updated') ||
                        bodyText.toLowerCase().includes('zone');
      const stayedOnPage = page.url().includes('templates');

      expect(hasSuccess || stayedOnPage).toBeTruthy();
    });

    test('should verify zone records after template update', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      test.skip(!zoneId, 'Zone was not created');

      await page.goto(`/zones/${zoneId}/edit`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(FATAL_ERROR_PATTERN);

      await expect(page).toHaveURL(/.*zones.*edit/);
    });
  });

  test.describe('Edge Cases', () => {
    test('should handle template with no linked zones gracefully', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const isolatedTemplateName = `isolated-template-${Date.now()}`;

      // Create isolated template
      await page.goto('/zones/templates/add');
      await page.locator('input[name*="name"]').first().fill(isolatedTemplateName);
      await page.locator('input[name*="descr"], textarea[name*="descr"]').first().fill('Isolated template');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Get template ID
      // The template was created just above, so its row and links are there
      await page.goto('/zones/templates');
      const row = page.locator(`tr:has-text("${isolatedTemplateName}")`);
      await expect(row).toHaveCount(1);

      await row.locator('a[href*="/edit"]').first().click();
      await expect(page.locator('body')).not.toContainText(FATAL_ERROR_PATTERN);

      // Clean up
      await page.goto('/zones/templates');
      await page.locator(`tr:has-text("${isolatedTemplateName}") a[href*="/delete"]`).first().click();
      await page.locator('button[type="submit"][name="confirm"]').click();
      await expect(page.locator('table')).not.toContainText(isolatedTemplateName);
    });

    test('should handle update with multiple zones linked to template', async ({ page }) => {
      // Creating a zone, rewriting two linked zones and cleaning up is more than
      // one test's budget on the API backend, where each zone is a round trip.
      test.slow();

      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      test.skip(!templateId, 'Template was not created');

      const secondZoneName = `update-test-2-${Date.now()}.example.com`;
      await page.goto('/zones/add/master');

      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
        .first()
        .fill(secondZoneName);

      const templateSelect = page.locator('select[name*="zone_templ"], select[name*="template"]').first();
      if (await templateSelect.count() > 0) {
        const options = await templateSelect.locator('option').all();
        for (const option of options) {
          const text = await option.textContent();
          if (text && text.includes(templateName)) {
            const value = await option.getAttribute('value');
            await templateSelect.selectOption(value);
            break;
          }
        }
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');

      await page.goto(`/zones/templates/${templateId}/edit`);

      const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"]').first();
      await expect(updateBtn).toBeVisible();

      await updateBtn.scrollIntoViewIfNeeded();
      // Two linked zones now, so the rewrite takes even longer on the API backend
      await updateBtn.click({ timeout: 60000 });
      await page.waitForLoadState('domcontentloaded');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(FATAL_ERROR_PATTERN);

      // Clean up second zone
      const secondZoneId = await findZoneIdByName(page, secondZoneName);
      if (secondZoneId) {
        await page.goto(`/zones/${secondZoneId}/delete`);
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();
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
        await page.goto(`/zones/${zoneId}/delete`, { timeout: 10000 });
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click({ timeout: 3000 });
          await page.waitForLoadState('networkidle', { timeout: 5000 });
        }
      }

      if (templateId) {
        await page.goto(`/zones/templates/${templateId}/delete`, { timeout: 10000 });
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click({ timeout: 3000 });
        }
      }

      await page.close();
    } catch {
      // Ignore cleanup errors
    }
  });
});
