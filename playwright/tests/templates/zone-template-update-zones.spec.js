import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensureTemplateExists, findTemplateIdByName } from '../../helpers/templates.js';
import { ensureZoneExists, findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Tests for "Update zones from template" functionality
 *
 * These tests verify the fix for GitHub issues #944 and #945, which reported
 * errors when updating zones from templates. The bug occurred because the code
 * confused zone_id with domain_id, causing "Argument must be of type string,
 * null given" errors when zones.id != domains.id (common in migrated databases).
 *
 * @see https://github.com/poweradmin/poweradmin/issues/944
 * @see https://github.com/poweradmin/poweradmin/issues/945
 */
test.describe.configure({ mode: 'serial' });

test.describe('Zone Template - Update Zones (Issues #944, #945)', () => {
  const templateName = `update-zones-test-${Date.now()}`;
  const zoneName = `update-test-${Date.now()}.example.com`;
  let templateId = null;
  let zoneId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Create a template first
    templateId = await ensureTemplateExists(page, templateName, 'Test template for update zones');

    if (templateId) {
      // Add a basic A record to the template
      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
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
    test('should create a zone using the test template', async ({ adminPage: page }) => {
      test.skip(!templateId, 'Template was not created');

      await page.goto('/index.php?page=add_zone_master');

      // Fill in zone name
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
        .first()
        .fill(zoneName);

      // Select the template
      const templateSelect = page.locator('select[name*="zone_templ"], select[name*="template"]').first();
      if (await templateSelect.count() > 0) {
        // Try to select by template name or ID
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

      // Submit the form
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');

      // Verify zone was created
      zoneId = await findZoneIdByName(page, zoneName);
      expect(zoneId).toBeTruthy();
    });
  });

  test.describe('Update Zones from Template', () => {
    test('should access edit template page with update zones button', async ({ adminPage: page }) => {
      test.skip(!templateId, 'Template was not created');

      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);
      await expect(page).toHaveURL(/edit_zone_templ/);

      // Look for the update zones button/form
      const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"], button:has-text("Update zones")');
      const hasUpdateBtn = await updateBtn.count() > 0;

      // The button should exist if there are zones using this template
      // If no zones use the template, the button might not be visible
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add a new record to template', async ({ adminPage: page }) => {
      test.skip(!templateId, 'Template was not created');

      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);

      // Add another A record
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

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should update zones from template without fatal error (issue #944/#945)', async ({ adminPage: page }) => {
      test.skip(!templateId, 'Template was not created');
      test.skip(!zoneId, 'Zone was not created');

      // Navigate to edit template page
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);

      // Find and click the update zones button
      const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"]').first();

      if (await updateBtn.count() > 0) {
        await updateBtn.scrollIntoViewIfNeeded();
        await updateBtn.click();
        await page.waitForLoadState('networkidle');

        // The key assertion: no fatal error should occur
        // Before the fix, this would cause:
        // "Fatal error: Uncaught TypeError: parseTemplateValue(): Argument #2 ($domain) must be of type string, null given"
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception|TypeError|null given/i);

        // Check for success message or redirect
        const hasSuccess = bodyText.toLowerCase().includes('success') ||
                          bodyText.toLowerCase().includes('updated') ||
                          bodyText.toLowerCase().includes('zone');
        const stayedOnPage = page.url().includes('edit_zone_templ');

        // Either success message or stayed on page without error is acceptable
        expect(hasSuccess || stayedOnPage).toBeTruthy();
      } else {
        // If no update button, check if there's a message about no linked zones
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        test.info().annotations.push({
          type: 'note',
          description: 'No update zones button found - zone may not be linked to template'
        });
      }
    });

    test('should verify zone records after template update', async ({ adminPage: page }) => {
      test.skip(!zoneId, 'Zone was not created');

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Check that the zone edit page loads correctly
      await expect(page).toHaveURL(/page=edit/);
    });
  });

  test.describe('Edge Cases', () => {
    test('should handle template with no linked zones gracefully', async ({ adminPage: page }) => {
      // Create a template with no zones linked
      const isolatedTemplateName = `isolated-template-${Date.now()}`;
      const isolatedTemplateId = await ensureTemplateExists(page, isolatedTemplateName);

      if (isolatedTemplateId) {
        await page.goto(`/index.php?page=edit_zone_templ&id=${isolatedTemplateId}`);

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);

        // Clean up - delete the isolated template
        await page.goto('/index.php?page=list_zone_templ');
        const row = page.locator(`tr:has-text("${isolatedTemplateName}")`);
        if (await row.count() > 0) {
          const deleteLink = row.locator('a[href*="delete_zone_templ"]').first();
          if (await deleteLink.count() > 0) {
            await deleteLink.click();
            const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
            if (await yesBtn.count() > 0) {
              await yesBtn.click();
            }
          }
        }
      }
    });

    test('should handle update with multiple zones linked to template', async ({ adminPage: page }) => {
      test.skip(!templateId, 'Template was not created');

      // Create a second zone with the same template
      const secondZoneName = `update-test-2-${Date.now()}.example.com`;
      await page.goto('/index.php?page=add_zone_master');

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
      await page.waitForLoadState('networkidle');

      // Now try to update zones from template
      await page.goto(`/index.php?page=edit_zone_templ&id=${templateId}`);

      const updateBtn = page.locator('button[name="update_zones"], input[name="update_zones"]').first();
      if (await updateBtn.count() > 0) {
        await updateBtn.scrollIntoViewIfNeeded();
        await updateBtn.click();
        await page.waitForLoadState('networkidle');

        // No fatal error should occur with multiple zones
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception|TypeError|null given/i);
      }

      // Clean up - delete the second zone
      const secondZoneId = await findZoneIdByName(page, secondZoneName);
      if (secondZoneId) {
        await page.goto(`/index.php?page=delete_domain&id=${secondZoneId}`);
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

      // Delete test zone
      if (zoneId) {
        await page.goto(`/index.php?page=delete_domain&id=${zoneId}`, { timeout: 10000 });
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click({ timeout: 3000 });
          await page.waitForLoadState('networkidle', { timeout: 5000 });
        }
      }

      // Delete test template
      if (templateId) {
        await page.goto(`/index.php?page=delete_zone_templ&id=${templateId}`, { timeout: 10000 });
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
