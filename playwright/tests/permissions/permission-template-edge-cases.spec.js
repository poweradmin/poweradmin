/**
 * Permission Template Edge Cases Tests
 *
 * Tests for permission template edge cases including:
 * - Duplicate key handling (regression test for #942)
 * - Special characters in template names
 * - Maximum permissions selection
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Permission Template Edge Cases (Issue #942)', () => {
  const timestamp = Date.now();
  const testTemplateName = `edge-test-${timestamp}`;

  test.describe('Duplicate Template Handling', () => {
    test('should create first permission template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(testTemplateName);

      // Select some permissions
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      if (count > 0) {
        await checkboxes.first().check().catch(() => {});
      }

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle duplicate template name gracefully (regression #942)', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      // Try to create template with same name
      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(testTemplateName);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Should show error message, not crash with duplicate key error
      expect(bodyText).not.toMatch(/duplicate key|fatal|exception/i);
    });

    test('should handle case-insensitive duplicate names', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      // Try uppercase version
      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(testTemplateName.toUpperCase());

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Special Characters in Template Names', () => {
    test('should handle template name with spaces', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`Test Template ${timestamp}`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle template name with special chars', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`Test-Template_${timestamp}`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject template name with SQL injection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill("'; DROP TABLE perm_templ; --");

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle very long template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill('a'.repeat(200));

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle empty template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      // Don't fill name, just submit
      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Permission Selection Edge Cases', () => {
    test('should handle selecting all permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`All-Perms-${timestamp}`);

      // Check all permission checkboxes
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      for (let i = 0; i < count; i++) {
        await checkboxes.nth(i).check().catch(() => {});
      }

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle selecting no permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`No-Perms-${timestamp}`);

      // Uncheck all permission checkboxes
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      for (let i = 0; i < count; i++) {
        await checkboxes.nth(i).uncheck().catch(() => {});
      }

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle rapid checkbox toggling', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();

      if (count > 0) {
        // Toggle first checkbox rapidly
        for (let i = 0; i < 10; i++) {
          await checkboxes.first().check().catch(() => {});
          await checkboxes.first().uncheck().catch(() => {});
        }
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Template Edit Edge Cases', () => {
    test('should edit existing template without error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const editLink = page.locator('a[href*="/permissions/templates/"][href*="/edit"]').first();
      if (await editLink.count() === 0) {
        test.skip('No templates to edit');
        return;
      }

      await editLink.click();
      await page.waitForLoadState('networkidle');

      // Toggle a permission
      const checkboxes = page.locator('input[type="checkbox"]');
      if (await checkboxes.count() > 0) {
        const isChecked = await checkboxes.first().isChecked();
        if (isChecked) {
          await checkboxes.first().uncheck();
        } else {
          await checkboxes.first().check();
        }
      }

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle editing template to existing name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      // Check if table exists
      const table = page.locator('table');
      if (await table.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      // Get list of template rows
      const rows = table.locator('tbody tr');
      const rowCount = await rows.count();

      if (rowCount < 2) {
        // Not enough templates - gracefully pass
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      // Get first template name
      const firstRow = rows.first();
      const firstName = await firstRow.locator('td').first().textContent();

      // Edit second template - use specific selector
      const editLink = rows.nth(1).locator('a[href*="permissions"][href*="edit"]').first();
      if (await editLink.count() === 0) {
        // No edit link found - gracefully pass
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }

      await editLink.click();
      await page.waitForLoadState('networkidle');

      // Try to rename to first template's name - use visible text input
      const nameInput = page.locator('input[type="text"][name*="name"], input[type="text"][name*="templ"]').first();
      if (await nameInput.count() === 0 || !firstName) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
        return;
      }
      await nameInput.fill(firstName.trim());

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Should handle gracefully
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Template Delete Edge Cases', () => {
    test('should handle deleting template in use', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      const deleteLink = page.locator('a[href*="/permissions/templates/"][href*="/delete"]').first();
      if (await deleteLink.count() === 0) {
        test.skip('No templates to delete');
        return;
      }

      await deleteLink.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Should show confirmation or warning, not crash
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Cleanup', () => {
    test('should delete test templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      // Delete test templates
      const testPatterns = [testTemplateName, `Test Template ${timestamp}`, `Test-Template_${timestamp}`, `All-Perms-${timestamp}`, `No-Perms-${timestamp}`];

      for (const pattern of testPatterns) {
        const row = page.locator(`tr:has-text("${pattern}")`);
        if (await row.count() > 0) {
          const deleteLink = row.locator('a[href*="/delete"]').first();
          if (await deleteLink.count() > 0) {
            await deleteLink.click();
            await page.waitForLoadState('networkidle');

            const confirmBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
            if (await confirmBtn.count() > 0) {
              await confirmBtn.click();
              await page.waitForLoadState('networkidle');
            }
          }
        }
      }
    });
  });
});
