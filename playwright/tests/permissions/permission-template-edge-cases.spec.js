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
import { createPermTemplate, findPermTemplateIdByName } from '../../helpers/templates.js';
import { uniqueName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

// Ids of the rows whose name cell equals the given text exactly (list order)
async function templateIdsNamed(page, name) {
  await page.goto('/permissions/templates');
  const rows = await page.locator('tbody tr').evaluateAll((trs, wanted) => trs
    .filter(tr => tr.querySelector('td')?.textContent.trim() === wanted)
    .map(tr => tr.querySelector('a[href$="/edit"]')?.getAttribute('href')), name);
  return rows.map(href => href?.match(/templates\/(\d+)\/edit/)?.[1]).filter(Boolean);
}

async function deletePermTemplate(page, id) {
  await page.goto(`/permissions/templates/${id}/delete`);
  await page.locator('button[name="confirm"]').click();
  await page.waitForLoadState('networkidle');
}

test.describe('Permission Template Edge Cases (Issue #942)', () => {
  // Every template this file creates carries this tag, so teardown can find them all
  const tag = uniqueName('pte');
  const testTemplateName = `${tag}-dup`;

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');
      const hrefs = await page.locator('tbody tr').evaluateAll((trs, prefix) => trs
        .filter(tr => tr.querySelector('td')?.textContent.trim().toLowerCase().startsWith(prefix))
        .map(tr => tr.querySelector('a[href$="/edit"]')?.getAttribute('href')), tag);
      for (const href of hrefs) {
        const id = href?.match(/templates\/(\d+)\/edit/)?.[1];
        if (id) {
          await deletePermTemplate(page, id);
        }
      }
    } finally {
      await page.close();
    }
  });

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

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      expect(await findPermTemplateIdByName(page, testTemplateName), `template ${testTemplateName} must be created`).toBeTruthy();
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

      await expect(page.locator('body')).not.toContainText(/duplicate key|fatal|exception/i);

      // duplicates are currently accepted: both rows are listed
      expect(await templateIdsNamed(page, testTemplateName)).toHaveLength(2);
    });

    test('should handle case-insensitive duplicate names', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      // Try uppercase version
      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`${tag.toUpperCase()}-DUP`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      // Refused or accepted depending on the database collation, never a crash
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Special Characters in Template Names', () => {
    test('should handle template name with spaces', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`${tag} Test Template`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should handle template name with special chars', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`${tag}-Test-Template_x`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should reject template name with SQL injection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`${tag}'; DROP TABLE perm_templ; --`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      // The table survived: the seeded Administrator template is still listed
      await page.goto('/permissions/templates');
      await expect(page.locator('tr').filter({ hasText: 'Administrator' }).first()).toBeVisible();
    });

    test('should handle very long template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`${tag}-${'a'.repeat(150)}`);

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should handle empty template name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      // Don't fill name, just submit; the required attribute keeps the form on the page
      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page).toHaveURL(/\/permissions\/templates\/add/);
    });
  });

  test.describe('Permission Selection Edge Cases', () => {
    test('should handle selecting all permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`${tag}-all-perms`);

      // Check all permission checkboxes
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      for (let i = 0; i < count; i++) {
        await checkboxes.nth(i).check().catch(() => {});
      }

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should handle selecting no permissions', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const nameInput = page.locator('input[name="name"], input[name*="templ"]').first();
      await nameInput.fill(`${tag}-no-perms`);

      // Uncheck all permission checkboxes
      const checkboxes = page.locator('input[type="checkbox"]');
      const count = await checkboxes.count();
      for (let i = 0; i < count; i++) {
        await checkboxes.nth(i).uncheck().catch(() => {});
      }

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should handle rapid checkbox toggling', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates/add');

      const checkbox = page.locator('input.permission-checkbox').first();
      await expect(checkbox).toBeVisible();

      // Toggle the first checkbox rapidly and end unchecked
      for (let i = 0; i < 10; i++) {
        await checkbox.check();
        await checkbox.uncheck();
      }
      await expect(checkbox).not.toBeChecked();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Template Edit Edge Cases', () => {
    test('should edit existing template without error', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // Own template, so the seeded ones keep their permissions
      const ownName = `${tag}-edit`;
      const templateId = await createPermTemplate(page, ownName);
      expect(templateId, `template ${ownName} must be created`).toBeTruthy();

      await page.goto(`/permissions/templates/${templateId}/edit`);

      const checkbox = page.locator('input.permission-checkbox').first();
      await expect(checkbox).toBeVisible();
      const wasChecked = await checkbox.isChecked();
      await checkbox.setChecked(!wasChecked);

      await page.locator('button[name="commit"]').click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      await page.goto(`/permissions/templates/${templateId}/edit`);
      await expect(page.locator('input.permission-checkbox').first()).toBeChecked({ checked: !wasChecked });
    });

    test('should handle editing template to existing name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const ownName = `${tag}-rename`;
      const otherName = `${tag}-rename-target`;
      const templateId = await createPermTemplate(page, ownName);
      expect(templateId, `template ${ownName} must be created`).toBeTruthy();
      expect(await createPermTemplate(page, otherName), `template ${otherName} must be created`).toBeTruthy();

      await page.goto(`/permissions/templates/${templateId}/edit`);
      await page.locator('#templ_name').fill(otherName);
      await page.locator('button[name="commit"]').click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/duplicate key|fatal|exception/i);

      // duplicates are currently accepted: the rename is stored and two rows share the name
      await page.goto(`/permissions/templates/${templateId}/edit`);
      await expect(page.locator('#templ_name')).toHaveValue(otherName);
      expect(await templateIdsNamed(page, otherName)).toHaveLength(2);
    });
  });

  test.describe('Template Delete Edge Cases', () => {
    test('should handle deleting template in use', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/permissions/templates');

      // Zone Manager is seeded and assigned to the manager user
      const row = page.locator('tbody tr', { has: page.getByRole('cell', { name: 'Zone Manager', exact: true }) });
      await expect(row).toHaveCount(1);
      await row.locator('a[href$="/delete"]').click();
      await page.waitForLoadState('networkidle');

      // Should show confirmation or warning, not crash; nothing is deleted here
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('button[name="confirm"]')).toBeVisible();
    });
  });
});
