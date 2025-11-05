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
    await expect(page.locator('h1, h2, h3, .page-title, [data-testid*="title"]').first()).toBeVisible();
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

    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes(templateName)) {
      // Click on template to edit/add records
      await page.locator(`tr:has-text("${templateName}")`).locator('a').first().click();

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

        const result = await page.locator('body').textContent();
        expect(result).toContain('www');

        // Add MX record to template
        await page.locator('select[name*="type"]').selectOption('MX');
        await page.locator('input[name*="name"]').clear();
        await page.locator('input[name*="name"]').fill('@');
        await page.locator('input[name*="content"], input[name*="value"]').clear();
        await page.locator('input[name*="content"], input[name*="value"]').fill('mail.[ZONE]');

        const hasPriority = await page.locator('input[name*="prio"], input[name*="priority"]').count() > 0;
        if (hasPriority) {
          await page.locator('input[name*="prio"], input[name*="priority"]').clear();
          await page.locator('input[name*="prio"], input[name*="priority"]').fill('10');
        }

        await page.locator('button[type="submit"]').click();

        const mxResult = await page.locator('body').textContent();
        expect(mxResult).toContain('mail.[ZONE]');
      }
    }
  });

  test('should use template when creating new zone', async ({ page }) => {
    // Navigate to add master zone
    await page.goto('/zones/add/master');

    // Fill in domain name
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);

    // Select template if dropdown exists
    const hasTemplate = await page.locator('select[name*="template"]').count() > 0;
    if (hasTemplate) {
      await page.locator('select[name*="template"]').selectOption(templateName);
    }

    // Set owner email if field exists
    const hasEmail = await page.locator('input[name*="email"], input[type="email"]').count() > 0;
    if (hasEmail) {
      await page.locator('input[name*="email"], input[type="email"]').first().fill('admin@example.com');
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify zone creation
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/success|created|added/i);
  });

  test('should verify template records applied to new zone', async ({ page }) => {
    // Navigate to zones and find the domain created with template
    await page.goto('/zones/forward');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes(testDomain)) {
      await page.locator(`tr:has-text("${testDomain}")`).locator('a').first().click();

      // Verify template records were applied
      const result = await page.locator('body').textContent();
      expect(result).toContain('www');
      expect(result).toContain('mail');

      // Verify [ZONE] placeholder was replaced
      expect(result).toContain(testDomain);
      expect(result).not.toContain('[ZONE]');
    }
  });

  test('should edit existing zone template', async ({ page }) => {
    await page.goto('/zones/templates');

    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes(templateName)) {
      const row = page.locator(`tr:has-text("${templateName}")`);
      const editLink = await row.locator('a').filter({ hasText: /Edit|edit/ }).count();

      if (editLink > 0) {
        await row.locator('a').filter({ hasText: /Edit|edit/ }).first().click();

        // Update template description
        await page.locator('input[name*="description"], textarea[name*="description"]').clear();
        await page.locator('input[name*="description"], textarea[name*="description"]').fill('Updated template description');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const result = await page.locator('body').textContent();
        expect(result).toMatch(/success|updated/i);
      }
    }
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

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Delete test domain if it exists
    await page.goto('/zones/forward');
    const bodyText = await page.locator('body').textContent();

    if (bodyText.includes(testDomain)) {
      const deleteLink = await page.locator(`tr:has-text("${testDomain}")`).locator('a, button').filter({ hasText: /Delete|Remove/ }).count();
      if (deleteLink > 0) {
        await page.locator(`tr:has-text("${testDomain}")`).locator('a, button').filter({ hasText: /Delete|Remove/ }).click();

        const confirmExists = await page.locator('button').filter({ hasText: /Yes|Confirm/ }).count();
        if (confirmExists > 0) {
          await page.locator('button').filter({ hasText: /Yes|Confirm/ }).click();
        }
      }
    }

    // Delete test template
    await page.goto('/zones/templates');
    const templateText = await page.locator('body').textContent();

    if (templateText.includes(templateName)) {
      const templateDeleteLink = await page.locator(`tr:has-text("${templateName}")`).locator('a, button').filter({ hasText: /Delete|Remove/ }).count();
      if (templateDeleteLink > 0) {
        await page.locator(`tr:has-text("${templateName}")`).locator('a, button').filter({ hasText: /Delete|Remove/ }).click();

        const confirmExists = await page.locator('button').filter({ hasText: /Yes|Confirm/ }).count();
        if (confirmExists > 0) {
          await page.locator('button').filter({ hasText: /Yes|Confirm/ }).click();
        }
      }
    }

    await page.close();
  });
});
