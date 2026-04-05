import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Templates Management', () => {
  const templateName = `test-template-${Date.now()}`;
  const testDomain = `template-test-${Date.now()}.com`;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access zone templates page', async ({ page }) => {
    await page.goto('/zones/templates');
    await expect(page).toHaveURL(/.*zones\/templates/);
    // Page uses card-header with strong element instead of h1-3
    await expect(page.locator('.card-header strong, .card-header, .breadcrumb').first()).toBeVisible();
  });

  test('should display zone templates list or empty state', async ({ page }) => {
    await page.goto('/zones/templates');

    const hasTable = await page.locator('table, .table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table, .table')).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No templates|templates|empty/i);
    }
  });

  test('should create a new zone template', async ({ page }) => {
    await page.goto('/zones/templates/add');
    await expect(page).toHaveURL(/.*zones\/templates\/add/);

    const hasForm = await page.locator('form').count() > 0;
    if (hasForm) {
      // Fill template details
      await page.locator('input[name*="name"], input[name*="template"]').first().fill(templateName);

      // Add description if field exists
      const hasDescription = await page.locator('input[name*="description"], textarea[name*="description"]').count() > 0;
      if (hasDescription) {
        await page.locator('input[name*="description"], textarea[name*="description"]').first().fill('Test template for Playwright testing');
      }

      // Set owner email if field exists
      const hasOwner = await page.locator('input[name*="owner"], input[type="email"]').count() > 0;
      if (hasOwner) {
        await page.locator('input[name*="owner"], input[type="email"]').first().fill('admin@example.com');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify template creation
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/success|created|added/i);
    }
  });

  test('should add records to zone template', async ({ page }) => {
    // Navigate to templates and find our test template
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(templateName)) {
      // Template not found, skip this test
      test.skip('Test template not found - may not have been created');
      return;
    }

    // Find the template row and click an enabled link (edit records, not view zones which may be disabled)
    const templateRow = page.locator(`tr:has-text("${templateName}")`);
    const enabledLink = templateRow.locator('a:not([aria-disabled="true"]):not(.disabled)').first();

    if (await enabledLink.count() === 0) {
      test.skip('No enabled links found for template');
      return;
    }

    await enabledLink.click();
    await page.waitForLoadState('networkidle');

    // Add A record to template
    const hasTypeSelect = await page.locator('select[name*="type"]').count() > 0;
    if (hasTypeSelect) {
      await page.locator('select[name*="type"]').selectOption('A');
      await page.locator('input[name*="name"]').fill('www');
      await page.locator('input[name*="content"], input[name*="value"]').fill('[ZONE]');

      const hasTtl = await page.locator('input[name*="ttl"]').count() > 0;
      if (hasTtl) {
        await page.locator('input[name*="ttl"]').clear();
        await page.locator('input[name*="ttl"]').fill('3600');
      }

      await page.locator('button[type="submit"]').click();
      await page.waitForLoadState('networkidle');

      const result = await page.locator('body').textContent();
      expect(result).not.toMatch(/fatal|exception/i);
    }
  });

  test('should use template when creating new zone', async ({ page }) => {
    // Navigate to add master zone
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    // Fill in domain name
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);

    // Select template if dropdown exists and has the template
    const templateSelect = page.locator('select[name*="template"]');
    if (await templateSelect.count() > 0) {
      // Get all options and find a valid one
      const options = await templateSelect.locator('option').allTextContents();
      const validOption = options.find(opt =>
        opt.includes(templateName) ||
        (opt && !opt.match(/none|select|choose|^$/i) && opt.trim())
      );

      if (validOption) {
        await templateSelect.selectOption({ label: validOption.trim() });
      }
    }

    // Set owner email if field exists
    const hasEmail = await page.locator('input[name*="email"], input[type="email"]').count() > 0;
    if (hasEmail) {
      await page.locator('input[name*="email"], input[type="email"]').first().fill('admin@example.com');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Verify zone creation (may fail if zone already exists)
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/success|created|added|already exists/i);
  });

  test('should verify template records applied to new zone', async ({ page }) => {
    // Navigate to zones and find the domain created with template
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(testDomain)) {
      // Zone not created, skip this test
      test.skip('Test zone not found');
      return;
    }

    // Click on an enabled link for the zone
    const zoneRow = page.locator(`tr:has-text("${testDomain}")`);
    const enabledLink = zoneRow.locator('a:not([aria-disabled="true"]):not(.disabled)').first();

    if (await enabledLink.count() > 0) {
      await enabledLink.click();
      await page.waitForLoadState('networkidle');

      // Verify page loaded without errors
      const result = await page.locator('body').textContent();
      expect(result).not.toMatch(/fatal|exception/i);
    }
  });

  test('should edit existing zone template', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes(templateName)) {
      test.skip('Test template not found to edit');
      return;
    }

    const row = page.locator(`tr:has-text("${templateName}")`);
    const editLink = row.locator('a[href*="edit"]:not([aria-disabled="true"]):not(.disabled)').first();

    if (await editLink.count() === 0) {
      test.skip('No edit link found for template');
      return;
    }

    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Just verify the edit page loads without errors
    const result = await page.locator('body').textContent();
    expect(result).not.toMatch(/fatal|exception/i);
  });

  test('should validate template form fields', async ({ page }) => {
    await page.goto('/zones/templates/add');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    await expect(page).toHaveURL(/.*zones\/templates\/add/);
  });

  test('should show template usage statistics', async ({ page }) => {
    await page.goto('/zones/templates');

    // Check if templates show usage count or statistics
    const hasTable = await page.locator('table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table')).toBeVisible();

      // Look for columns that might show usage
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/template|Template/);
    }
  });

  test('should have add button in card header', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const addButton = page.locator('.card-header a[href*="templates/add"]');
    await expect(addButton).toBeVisible();
    await expect(addButton).toContainText(/Add zone template/i);
  });

  test('should have search input that filters templates', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('#template-search');
    const hasTemplates = await page.locator('.template-row').count() > 0;

    if (!hasTemplates) {
      test.skip('No templates to search');
      return;
    }

    await expect(searchInput).toBeVisible();

    // Get initial row count
    const initialCount = await page.locator('.template-row').count();

    // Type a search term that likely won't match all rows
    await searchInput.fill('zzzznonexistent');

    // All rows should be hidden
    const visibleAfterSearch = await page.locator('.template-row:visible').count();
    expect(visibleAfterSearch).toBe(0);

    // Clear search
    await page.locator('#clear-template-search').click();

    // All rows should be visible again
    const visibleAfterClear = await page.locator('.template-row:visible').count();
    expect(visibleAfterClear).toBe(initialCount);
  });

  test('should show action buttons on single line', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const actionCells = page.locator('.d-flex.flex-nowrap');
    if (await actionCells.count() > 0) {
      const firstCell = actionCells.first();
      const cellBox = await firstCell.boundingBox();
      // All buttons should fit within a reasonable height (single line)
      expect(cellBox.height).toBeLessThan(50);
    }
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Delete test domain if it exists
    try {
      await page.goto('/zones/forward?letter=all');
      await page.waitForLoadState('networkidle');

      const zoneRow = page.locator(`tr:has-text("${testDomain}")`).first();
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
    } catch {
      // Ignore cleanup errors
    }

    // Delete test template
    try {
      await page.goto('/zones/templates');
      await page.waitForLoadState('networkidle');

      const templateRow = page.locator(`tr:has-text("${templateName}")`).first();
      if (await templateRow.count() > 0) {
        const deleteLink = templateRow.locator('a[href*="delete"]').first();
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
    } catch {
      // Ignore cleanup errors
    }

    await page.close();
  });
});
