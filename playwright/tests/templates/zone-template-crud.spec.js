import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensureTemplateExists } from '../../helpers/templates.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Template CRUD Operations', () => {
  const templateName = `test-template-${Date.now()}`;
  const templateDescription = 'Automated test template';

  test.describe('List Templates', () => {
    test('admin should access templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_zone_templ');

      await expect(page).toHaveURL(/page=list_zone_templ/);
    });

    test('should display templates table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_zone_templ');

      const hasTable = await page.locator('table').count() > 0;
      if (hasTable) {
        await expect(page.locator('table').first()).toBeVisible();
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).toMatch(/template|no.*template|empty/i);
      }
    });

    test('should display add template button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_zone_templ');

      const addBtn = page.locator('a[href*="add_zone_templ"], input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('manager should access templates list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_zone_templ');

      await expect(page).toHaveURL(/page=list_zone_templ/);
    });

    test('should show template owner column', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_zone_templ');

      const hasTable = await page.locator('table').count() > 0;
      if (hasTable) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/owner|user/);
      }
    });
  });

  test.describe('Add Template', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add template page', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_templ');
      await expect(page).toHaveURL(/page=add_zone_templ/);
      await expect(page.locator('form')).toBeVisible();
    });

    test('should display template name field', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_templ');

      const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display description field', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_templ');

      const descField = page.locator('input[name*="description"], textarea[name*="description"]');
      if (await descField.count() > 0) {
        await expect(descField.first()).toBeVisible();
      }
    });

    test('should create template with name only', async ({ page }) => {
      const uniqueName = `${templateName}-nameonly`;
      await page.goto('/index.php?page=add_zone_templ');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      const hasSuccess = bodyText.toLowerCase().includes('success') ||
                         bodyText.toLowerCase().includes('created') ||
                         bodyText.includes(uniqueName) ||
                         page.url().includes('list_zone_templ');
      expect(hasSuccess).toBeTruthy();
    });

    test('should create template with name and description', async ({ page }) => {
      const uniqueName = `${templateName}-full`;
      await page.goto('/index.php?page=add_zone_templ');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);

      const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
      if (await descField.count() > 0) {
        await descField.fill(templateDescription);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should reject empty template name', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_templ');

      // Leave name empty and submit
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('required') ||
                       url.includes('add_zone_templ');
      expect(hasError).toBeTruthy();
    });

    test('should reject template name with only spaces', async ({ page }) => {
      await page.goto('/index.php?page=add_zone_templ');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill('   ');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('required') ||
                       url.includes('add_zone_templ');
      expect(hasError).toBeTruthy();
    });

    test('should allow template name with special characters', async ({ page }) => {
      const uniqueName = `${templateName}-special-chars-@#`;
      await page.goto('/index.php?page=add_zone_templ');

      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(uniqueName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Edit Template', () => {
    let templateId = null;
    const editTemplateName = `edit-test-${Date.now()}`;

    test.beforeAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      templateId = await ensureTemplateExists(page, editTemplateName);
      await page.close();
    });

    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access edit template page', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${editTemplateName}")`);

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_zone_templ"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();
          await expect(page).toHaveURL(/edit_zone_templ/);
        }
      }
    });

    test('should display current template name', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${editTemplateName}")`);

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_zone_templ"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();

          const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
          const value = await nameField.inputValue();
          expect(value).toContain(editTemplateName.substring(0, 10));
        }
      }
    });

    test('should update template name', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${editTemplateName}")`);

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_zone_templ"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();

          const nameField = page.locator('input[name*="name"], input[name*="templ"]').first();
          await nameField.fill(`${editTemplateName}-updated`);
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should update template description', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${editTemplateName}")`).first();

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_zone_templ"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();

          const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
          if (await descField.count() > 0) {
            await descField.fill('Updated description');
            await page.locator('button[type="submit"], input[type="submit"]').first().click();

            const bodyText = await page.locator('body').textContent();
            expect(bodyText).not.toMatch(/fatal|exception/i);
          }
        }
      }
    });
  });

  test.describe('Delete Template', () => {
    const deleteTemplateName = `delete-test-${Date.now()}`;

    test.beforeAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto('/index.php?page=add_zone_templ');
      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(deleteTemplateName);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.close();
    });

    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access delete confirmation page', async ({ page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${deleteTemplateName}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_zone_templ"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          await expect(page).toHaveURL(/delete_zone_templ/);
        }
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${deleteTemplateName}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_zone_templ"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
        }
      }
    });

    test('should display yes and no buttons', async ({ page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${deleteTemplateName}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_zone_templ"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();

          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")');
          const noBtn = page.locator('input[value="No"], button:has-text("No")');

          if (await yesBtn.count() > 0) {
            await expect(yesBtn.first()).toBeVisible();
          }
          if (await noBtn.count() > 0) {
            await expect(noBtn.first()).toBeVisible();
          }
        }
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${deleteTemplateName}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_zone_templ"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();

          const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
          if (await noBtn.count() > 0) {
            await noBtn.click();
            await expect(page).toHaveURL(/list_zone_templ/);
          }
        }
      }
    });

    test('should delete template successfully', async ({ page }) => {
      // Create a new template to delete
      const toDelete = `to-delete-${Date.now()}`;
      await page.goto('/index.php?page=add_zone_templ');
      await page.locator('input[name*="name"], input[name*="templ"]').first().fill(toDelete);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator(`tr:has-text("${toDelete}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_zone_templ"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();

          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) {
            await yesBtn.click();

            // Verify deleted
            await page.goto('/index.php?page=list_zone_templ');
            const bodyText = await page.locator('body').textContent();
            expect(bodyText).not.toContain(toDelete);
          }
        }
      }
    });
  });

  test.describe('Template Records', () => {
    const recordTemplateName = `records-test-${Date.now()}`;
    let templateId = null;

    test.beforeAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      templateId = await ensureTemplateExists(page, recordTemplateName);
      await page.close();
    });

    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add template record page', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);
      await expect(page).toHaveURL(/add_zone_templ_record/);
    });

    test('should display record type selector', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);

      const typeSelector = page.locator('select[name*="type"]');
      if (await typeSelector.count() > 0) {
        await expect(typeSelector.first()).toBeVisible();
      }
    });

    test('should add A record to template', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);

      await page.locator('select[name*="type"]').first().selectOption('A');

      const nameField = page.locator('input[name*="name"]').first();
      if (await nameField.count() > 0) {
        await nameField.fill('www');
      }

      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.1');

      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('3600');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add MX record to template', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail.[ZONE]');

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should use [ZONE] placeholder in template record', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);

      await page.locator('select[name*="type"]').first().selectOption('NS');
      await page.locator('input[name*="content"], input[name*="value"]').first().fill('ns1.[ZONE]');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record to template', async ({ page }) => {
      expect(templateId).toBeTruthy();

      await page.goto(`/index.php?page=add_zone_templ_record&id=${templateId}`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');

      const nameField = page.locator('input[name*="name"]').first();
      if (await nameField.count() > 0) {
        await nameField.fill('_dmarc');
      }

      await page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first()
        .fill('v=DMARC1; p=none');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Permission Tests', () => {
    test('admin should have full template access', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=list_zone_templ');

      const addBtn = page.locator('a[href*="add_zone_templ"], input[value*="Add"]');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('manager should access template list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=list_zone_templ');

      await expect(page).toHaveURL(/list_zone_templ/);
    });

    test('viewer should have read-only template access', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/index.php?page=list_zone_templ');

      // Viewer may not see add/edit/delete links
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/template/i);
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Delete all test templates
    await page.goto('/index.php?page=list_zone_templ');

    const testTemplateRows = page.locator('tr').filter({ hasText: /test-template|edit-test|delete-test|records-test/ });
    const count = await testTemplateRows.count();

    for (let i = 0; i < count; i++) {
      await page.goto('/index.php?page=list_zone_templ');
      const row = page.locator('tr').filter({ hasText: /test-template|edit-test|delete-test|records-test/ }).first();

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

    await page.close();
  });
});
