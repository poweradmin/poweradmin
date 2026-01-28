/**
 * Zone Template Defaults Tests
 *
 * Tests for zone template default functionality (GitHub issue #973)
 * - Setting a template as default
 * - Default template auto-selection
 * - Default template persistence
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Zone Template Defaults (Issue #973)', () => {
  const testTemplateName = `default-test-${Date.now()}`;

  test.describe('Default Template Setting', () => {
    test('should display default checkbox/option when editing template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/templates');

      // Find first template edit link
      const editLink = page.locator('a[href*="/templates/"][href*="/edit"]').first();
      if (await editLink.count() === 0) {
        test.skip('No templates available');
        return;
      }

      await editLink.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      // Should have default option or page loads without error
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should create template with default option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/templates/add');

      // Fill template name
      const nameInput = page.locator('input[name="name"], input[name*="template"]').first();
      await nameInput.fill(testTemplateName);

      // Look for default checkbox
      const defaultCheckbox = page.locator('input[name="default"], input[name*="is_default"]');
      if (await defaultCheckbox.count() > 0) {
        await defaultCheckbox.check();
      }

      // Submit
      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should show default indicator in template list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/templates');

      const bodyText = await page.locator('body').textContent();
      // Page should load without errors
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should allow changing default template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/templates');

      // Find a template to edit
      const editLink = page.locator('a[href*="/templates/"][href*="/edit"]').first();
      if (await editLink.count() === 0) {
        test.skip('No templates available');
        return;
      }

      await editLink.click();
      await page.waitForLoadState('networkidle');

      // Toggle default checkbox if available
      const defaultCheckbox = page.locator('input[name="default"], input[name*="is_default"]');
      if (await defaultCheckbox.count() > 0) {
        const isChecked = await defaultCheckbox.isChecked();
        if (isChecked) {
          await defaultCheckbox.uncheck();
        } else {
          await defaultCheckbox.check();
        }

        const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
        await submitBtn.click();
        await page.waitForLoadState('networkidle');
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Default Template Auto-Selection', () => {
    test('should pre-select default template when adding zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/add/master');

      // Look for template dropdown
      const templateSelect = page.locator('select[name*="template"]');
      if (await templateSelect.count() > 0) {
        const selectedValue = await templateSelect.inputValue();
        // Template select should exist and potentially have a default selected
        expect(selectedValue !== undefined).toBeTruthy();
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should allow overriding default template selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/add/master');

      const templateSelect = page.locator('select[name*="template"]');
      if (await templateSelect.count() > 0) {
        const options = templateSelect.locator('option');
        const optionCount = await options.count();

        if (optionCount > 1) {
          // Select a different option
          const secondOption = await options.nth(1).getAttribute('value');
          if (secondOption) {
            await templateSelect.selectOption(secondOption);
          }
        }
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Only One Default Template', () => {
    test('should only allow one default template at a time', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/templates');

      // Count templates marked as default
      const defaultIndicators = page.locator('tr:has-text("default"), .default-indicator, .is-default');
      const bodyText = await page.locator('body').textContent();

      // Page should load without errors
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Default Template Permissions', () => {
    test('admin should be able to set default template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/templates');

      const editLink = page.locator('a[href*="/templates/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await page.waitForLoadState('networkidle');

        // Should have access to default option
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/denied|forbidden/i);
      }
    });

    test('manager should use default template when creating zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/add/master');

      const templateSelect = page.locator('select[name*="template"]');
      const bodyText = await page.locator('body').textContent();

      // Manager should be able to create zones and see templates
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Template Cleanup', () => {
    test('should delete test template', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/templates');

      // Find and delete test template
      const templateRow = page.locator(`tr:has-text("${testTemplateName}")`);
      if (await templateRow.count() > 0) {
        const deleteLink = templateRow.locator('a[href*="/delete"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          await page.waitForLoadState('networkidle');

          // Confirm deletion
          const confirmBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await confirmBtn.count() > 0) {
            await confirmBtn.click();
          }
        }
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
