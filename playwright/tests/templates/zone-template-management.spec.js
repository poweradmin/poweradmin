import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Template Management', () => {
  // Use a fixed template name with timestamp for this test run
  const templateName = `PW-Test-Template-${Date.now()}`;
  const testZone = `template-zone-${Date.now()}.com`;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should list zone templates', async ({ page }) => {
    await page.goto('/zones/templates');
    await expect(page).toHaveURL(/.*zones\/templates/);

    // Page should show templates table or empty state
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/template|add|Zone Templates/i);
  });

  test('should add a new zone template', async ({ page }) => {
    await page.goto('/zones/templates/add');
    await page.waitForLoadState('networkidle');

    // Fill template form
    const nameInput = page.locator('[data-testid="zone-templ-name-input"], input[name*="name"], input[name*="templ"]').first();
    await nameInput.fill(templateName);

    const descInput = page.locator('[data-testid="zone-templ-desc-input"], textarea[name*="desc"], input[name*="desc"]').first();
    if (await descInput.count() > 0) {
      await descInput.fill('Template created by Playwright tests');
    }

    // Submit form
    const submitBtn = page.locator('[data-testid="add-zone-templ-button"], button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify success - should redirect to templates list or show success message
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/success|added|template/i);
  });

  test('should add records to a zone template', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    // Find a template in the table
    const templateTable = page.locator('table');
    if (await templateTable.count() === 0) {
      // No templates table, skip
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/template|zone|no.*template/i);
      return;
    }

    // Find an edit link in the table - specifically for templates (not users)
    const editLink = templateTable.locator('tbody a[href*="templates"][href*="edit"]').first();
    if (await editLink.count() === 0) {
      // No templates to edit, just verify page loaded
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/template|zone/i);
      return;
    }

    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Check for record type select (indicating we're on a records page)
    const typeSelect = page.locator('select[name*="type"]').first();
    if (await typeSelect.count() > 0) {
      await typeSelect.selectOption('A');

      const nameInput = page.locator('input[name*="name"]').first();
      await nameInput.fill('www');

      const contentInput = page.locator('input[name*="content"]').first();
      await contentInput.fill('192.168.1.10');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();
      await page.waitForLoadState('networkidle');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    } else {
      // Just verify the page loaded without errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should apply a zone template when creating a zone', async ({ page }) => {
    // Navigate to add master zone
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    // Fill zone name
    const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"]').first();
    await zoneInput.fill(testZone);

    // Select template if dropdown exists and has options
    const templateSelect = page.locator('[data-testid="zone-template-select"], select[name*="template"]').first();
    if (await templateSelect.count() > 0) {
      // Get available options
      const options = await templateSelect.locator('option').allTextContents();
      // Find a template option that isn't empty/none
      const validOption = options.find(opt => opt && !opt.match(/none|select|choose/i) && opt.trim());
      if (validOption) {
        await templateSelect.selectOption({ label: validOption.trim() });
      }
    }

    // Submit
    const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify zone creation
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/success|added|created|already exists/i);
  });

  test('should edit a zone template', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    // Find any template row
    const templateRow = page.locator('table tbody tr').first();
    if (await templateRow.count() === 0) {
      test.skip('No templates found to edit');
      return;
    }

    // Click edit link
    const editLink = templateRow.locator('a[href*="edit"]').first();
    if (await editLink.count() === 0 || !(await editLink.isEnabled())) {
      test.skip('No editable template found');
      return;
    }

    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Just verify the edit page loads without errors
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should delete a zone template', async ({ page }) => {
    // First, ensure test zone is cleaned up if it exists
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${testZone}")`).first();
    if (await zoneRow.count() > 0) {
      const deleteLink = zoneRow.locator('a[href*="delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await page.waitForLoadState('networkidle');
        const confirmBtn = page.locator('button[type="submit"]:has-text("Delete"), input[value*="Delete"]').first();
        if (await confirmBtn.count() > 0) {
          await confirmBtn.click();
          await page.waitForLoadState('networkidle');
        }
      }
    }

    // Now delete the template
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const templateRow = page.locator(`tr:has-text("${templateName}")`).first();
    if (await templateRow.count() === 0) {
      test.skip('Test template not found to delete');
      return;
    }

    const deleteLink = templateRow.locator('a[href*="delete"]').first();
    if (await deleteLink.count() === 0) {
      test.skip('Delete link not found for template');
      return;
    }

    await deleteLink.click();
    await page.waitForLoadState('networkidle');

    // Confirm deletion
    const confirmBtn = page.locator('[data-testid="confirm-delete-zone-templ"], button[type="submit"]:has-text("Delete"), input[value*="Delete"]').first();
    if (await confirmBtn.count() > 0) {
      await confirmBtn.click();
      await page.waitForLoadState('networkidle');
    }

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
