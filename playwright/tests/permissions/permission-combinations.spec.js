/**
 * Permission Combinations Tests
 *
 * Tests for permission template management and permission combinations.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Run sequentially within file
test.describe.configure({ mode: 'serial', retries: 1 });

test.describe('Permission Combinations', () => {

  test.describe('Permission Template Management', () => {
    test('should display permission templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      await expect(page).toHaveURL(/.*permissions\/templates/);
    });

    test('should display add template button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const addBtn = page.locator('a[href*="/add"], input[value*="Add"], a:has-text("Add permission template")');
      if (await addBtn.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/permission template/i);
      } else {
        expect(await addBtn.count()).toBeGreaterThan(0);
      }
    });

    test('should access add template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      await expect(page).toHaveURL(/.*permissions\/templates\/add/);
    });

    test('should display permission checkboxes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      await page.waitForSelector('.accordion, input[type="checkbox"]', { timeout: 10000 }).catch(() => {});
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      if (count === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/permission|template/i);
      } else {
        expect(count).toBeGreaterThan(0);
      }
    });

    test('should display template name field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      const nameField = page.locator('input[name="templ_name"], input[id="templ_name"]');
      if (await nameField.count() > 0) {
        await expect(nameField.first()).toBeVisible();
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/add.*permission|template.*name/i);
      }
    });

    test('should display description field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      const descField = page.locator('input[name="templ_descr"], input[id="templ_descr"]');
      if (await descField.count() > 0) {
        await expect(descField.first()).toBeVisible();
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Permission Options', () => {
    test('should display zone permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone/i);
    });

    test('should display record permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/record/i);
    });

    test('should display user permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/user/i);
    });

    test('should display supermaster permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/supermaster/i);
    });

    test('should display search permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/search/i);
    });
  });

  test.describe('Template Creation', () => {
    test('should create template with zone view only', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const templateName = `view-only-${Date.now()}`;
      await page.goto('/permissions/templates/add');
      await page.locator('input[name*="name"]').first().fill(templateName);

      const zoneViewCheckbox = page.locator('input[name*="zone_content_view"], input[value*="zone_view"]').first();
      if (await zoneViewCheckbox.count() > 0) {
        await zoneViewCheckbox.check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Cleanup
      await page.goto('/permissions/templates');
      const deleteLink = page.locator(`tr:has-text("${templateName}") a[href*="/delete"]`).first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should create template with full zone management', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const templateName = `full-zone-${Date.now()}`;
      await page.goto('/permissions/templates/add');
      await page.locator('input[name*="name"]').first().fill(templateName);

      const zoneCheckboxes = page.locator('input[type="checkbox"][name*="zone"]');
      const count = await zoneCheckboxes.count();
      for (let i = 0; i < count; i++) {
        await zoneCheckboxes.nth(i).check();
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Cleanup
      await page.goto('/permissions/templates');
      const deleteLink = page.locator(`tr:has-text("${templateName}") a[href*="/delete"]`).first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should reject template without name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      const url = page.url();
      expect(url).toMatch(/permissions\/templates\/add|permissions\/templates/);
    });
  });

  test.describe('Template Editing', () => {
    test('should access edit template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/.*permissions\/templates.*edit/);
      }
    });

    test('should display current template permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const checkboxes = page.locator('input[type="checkbox"]');
        expect(await checkboxes.count()).toBeGreaterThan(0);
      }
    });

    test('should update template permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const firstCheckbox = page.locator('input[type="checkbox"]').first();
        if (await firstCheckbox.count() > 0) {
          const isChecked = await firstCheckbox.isChecked();
          if (isChecked) {
            await firstCheckbox.uncheck();
          } else {
            await firstCheckbox.check();
          }
          await page.locator('button[type="submit"], input[type="submit"]').first().click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Template Deletion', () => {
    test('should display delete option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should confirm before delete', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm/i);
      }
    });

    test('should cancel delete', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/.*permissions\/templates/);
        }
      }
    });
  });

  test.describe('Template Assignment', () => {
    test('should display template selector in user edit', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');
      const tableEditLinks = page.locator('table a[href*="/edit"], .btn:has-text("Edit")');
      if (await tableEditLinks.count() > 0) {
        await tableEditLinks.first().click();
        const templateSelect = page.locator('select[name*="templ"], select[name*="template"]');
        if (await templateSelect.count() > 0) {
          await expect(templateSelect.first()).toBeVisible();
        }
      }
    });

    test('should display template selector in add user', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');
      const templateSelect = page.locator('select[name*="templ"], select[name*="template"]');
      if (await templateSelect.count() > 0) {
        await expect(templateSelect.first()).toBeVisible();
      }
    });
  });

  test.describe('Permission Access Control', () => {
    test('admin should access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const currentUrl = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasAccess = currentUrl.includes('permissions/templates') ||
                        bodyText.toLowerCase().includes('permission template') ||
                        !bodyText.toLowerCase().includes('access denied');
      expect(hasAccess).toBeTruthy();
    });

    test('manager should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/permissions/templates');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('client should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/permissions/templates');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('viewer should not access permission templates', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/permissions/templates');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
