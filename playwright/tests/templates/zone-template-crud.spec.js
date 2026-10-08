/**
 * Zone Template CRUD Operations Tests
 *
 * Tests for zone template management including listing,
 * adding, editing, and deleting zone templates.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { createTemplate, deleteTemplate } from '../../helpers/templates.js';
import { uniqueName } from '../../helpers/zones.js';

// Submits the add form with the given name and removes the template again
async function createAndRemove(page, name, description = '') {
  await page.goto('/zones/templates/add');
  await page.locator('input[name*="name"], input[name*="templ"]').first().fill(name);
  if (description) {
    await page.locator('input[name*="descr"], textarea[name*="descr"]').first().fill(description);
  }
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

  await page.goto('/zones/templates');
  const row = page.locator('.template-row').filter({ hasText: name });
  await expect(row, `template ${name} must be listed`).toHaveCount(1);
  const href = await row.locator('a[href$="/edit"]').getAttribute('href');
  await deleteTemplate(page, href.match(/templates\/(\d+)\/edit/)[1]);
}

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone Template CRUD Operations', () => {
  const templateName = uniqueName('ztc');
  const templateDescription = 'Automated test template';

  test.describe('List Templates', () => {
    test('admin should access templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');
      await expect(page).toHaveURL(/.*zones\/templates/);
    });

    test('should display templates table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      await expect(page.locator('table').first()).toBeVisible();
    });

    test('should display add template button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const addBtn = page.locator('a[href*="/zones/templates/add"], input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('manager should access templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/templates');
      await expect(page).toHaveURL(/.*zones\/templates/);
    });

    test('should show template owner column', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/owner|user/);
    });
  });

  test.describe('Add Template', () => {
    test('should access add template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');
      await expect(page).toHaveURL(/.*zones\/templates\/add/);
      await expect(page.locator('form')).toBeVisible();
    });

    test('should display template name field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');

      const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display description field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');

      // The field is named templ_descr, so a name*="description" selector never matched
      const descField = page.locator('input[name*="descr"], textarea[name*="descr"]');
      await expect(descField.first()).toBeVisible();
    });

    test('should create template with name only', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await createAndRemove(page, `${templateName}-nameonly`);
    });

    test('should create template with name and description', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await createAndRemove(page, `${templateName}-full`, templateDescription);
    });

    test('should reject empty template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/templates/add');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('required') ||
                       url.includes('/zones/templates/add');
      expect(hasError).toBeTruthy();
    });

    test('should allow template name with special characters', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await createAndRemove(page, `${templateName}-special-chars-@#`);
    });
  });

  test.describe('Edit Template', () => {
    test('should access edit template page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const ownName = `${templateName}-access`;
      const templateId = await createTemplate(page, ownName);
      try {
        expect(templateId, `template ${ownName} must be created`).toBeTruthy();
        await page.goto('/zones/templates');
        await page.locator('.template-row').filter({ hasText: ownName }).locator('a[href$="/edit"]').click();
        await expect(page).toHaveURL(new RegExp(`/zones/templates/${templateId}/edit`));
      } finally {
        if (templateId) {
          await deleteTemplate(page, templateId);
        }
      }
    });

    test('should display current template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const ownName = `${templateName}-display`;
      const templateId = await createTemplate(page, ownName);
      try {
        expect(templateId, `template ${ownName} must be created`).toBeTruthy();
        await page.goto(`/zones/templates/${templateId}/edit`);

        // The records table above this form has its own name inputs, so target the
        // template name field directly rather than the first match on the page.
        await expect(page.locator('#templ_name')).toHaveValue(ownName);
      } finally {
        if (templateId) {
          await deleteTemplate(page, templateId);
        }
      }
    });

    test('should update template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const ownName = `${templateName}-rename`;
      const templateId = await createTemplate(page, ownName);
      try {
        expect(templateId).toBeTruthy();

        const renamed = `${ownName}-updated`;
        await page.goto(`/zones/templates/${templateId}/edit`);
        await page.locator('#templ_name').fill(renamed);
        // Save the template details, not the first submit on the page: that is
        // "Update zones", which renders disabled when no zones use the template.
        await page.locator('button[type="submit"][name="edit"]').click();

        await page.goto('/zones/templates');
        await expect(page.locator(`tr:has-text("${renamed}")`)).toHaveCount(1);
      } finally {
        await deleteTemplate(page, templateId);
      }
    });

    test('should update template description', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const ownName = `${templateName}-descr`;
      const templateId = await createTemplate(page, ownName);
      try {
        expect(templateId, `template ${ownName} must be created`).toBeTruthy();
        const newDescription = `Updated description ${ownName}`;
        await page.goto(`/zones/templates/${templateId}/edit`);
        await page.locator('#templ_descr').fill(newDescription);
        // Save the template details, not the first submit on the page: that is
        // "Update zones", which renders disabled when no zones use the template.
        await page.locator('button[type="submit"][name="edit"]').click();
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

        await page.goto(`/zones/templates/${templateId}/edit`);
        await expect(page.locator('#templ_descr')).toHaveValue(newDescription);
      } finally {
        if (templateId) {
          await deleteTemplate(page, templateId);
        }
      }
    });
  });

  test.describe('Delete Template', () => {
    // Opens the delete page of an own template; nothing is deleted until confirmed
    async function withOwnTemplate(page, suffix, fn) {
      const ownName = `${templateName}-${suffix}`;
      const templateId = await createTemplate(page, ownName);
      try {
        expect(templateId, `template ${ownName} must be created`).toBeTruthy();
        await page.goto('/zones/templates');
        await page.locator('.template-row').filter({ hasText: ownName }).locator('a[href$="/delete"]').click();
        await fn(templateId);
      } finally {
        if (templateId) {
          await deleteTemplate(page, templateId);
        }
      }
    }

    test('should access delete confirmation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await withOwnTemplate(page, 'delete-access', async templateId => {
        await expect(page).toHaveURL(new RegExp(`/zones/templates/${templateId}/delete`));
      });
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await withOwnTemplate(page, 'delete-confirm', async () => {
        await expect(page.locator('body')).toContainText(/delete|confirm|sure/i);
      });
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await withOwnTemplate(page, 'delete-cancel', async () => {
        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        await expect(noBtn).toBeVisible();
        await noBtn.click();
        await expect(page).toHaveURL(/.*zones\/templates$/);
      });
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not have add template button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/templates');

      const addBtn = page.locator('a[href*="/zones/templates/add"]');
      expect(await addBtn.count()).toBe(0);
    });

    test('client should have limited template permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/templates');

      // Client can view templates page and may have access to templates they own
      // Just verify the page loads without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
