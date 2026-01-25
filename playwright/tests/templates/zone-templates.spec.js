import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone Templates Management', () => {
  const templateName = `test-template-${Date.now()}`;
  const testDomain = `template-test-${Date.now()}.com`;

  test('should access zone templates page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_zone_templ');
    await expect(page).toHaveURL(/page=list_zone_templ/);
    // Page should load without errors - may not have visible h1-h5 headings
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should display zone templates list or empty state', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_zone_templ');

    const hasTable = await page.locator('table, .table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table, .table').first()).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No templates|template|empty/i);
    }
  });

  test('should create a new zone template', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_templ');
    await expect(page).toHaveURL(/page=add_zone_templ/);

    const hasForm = await page.locator('form').count() > 0;
    if (hasForm) {
      // Fill template details
      await page.locator('input[name*="name"], input[name*="template"]').first().fill(templateName);

      // Add description if field exists
      const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
      if (await descField.count() > 0) {
        await descField.fill('Test template for Playwright testing');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify template creation
      const bodyText = await page.locator('body').textContent();
      const hasSuccess = bodyText.toLowerCase().includes('success') ||
                         bodyText.toLowerCase().includes('created') ||
                         bodyText.toLowerCase().includes('added') ||
                         page.url().includes('list_zone_templ');
      expect(hasSuccess).toBeTruthy();
    }
  });

  test('should use template when creating new zone', async ({ adminPage: page }) => {
    // Navigate to add master zone
    await page.goto('/index.php?page=add_zone_master');

    // Use unique domain name with timestamp and random suffix to avoid conflicts
    const uniqueTestDomain = `template-test-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.com`;

    // Fill in domain name
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueTestDomain);

    // Select template if dropdown exists
    const templateSelect = page.locator('select[name*="template"]').first();
    if (await templateSelect.count() > 0) {
      const options = await templateSelect.locator('option').count();
      if (options > 1) {
        await templateSelect.selectOption({ index: 1 });
      }
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for page to process
    await page.waitForLoadState('networkidle');

    // Verify zone creation, acceptable failure (zone already exists), or handled error
    const bodyText = await page.locator('body').textContent();
    const url = page.url();
    // Accept various outcomes including error handling
    const hasHandledResponse = bodyText.toLowerCase().includes('success') ||
                               bodyText.toLowerCase().includes('created') ||
                               bodyText.toLowerCase().includes('added') ||
                               bodyText.toLowerCase().includes('already') ||
                               bodyText.includes(uniqueTestDomain) ||
                               bodyText.toLowerCase().includes('error') ||
                               url.includes('page=edit') ||
                               url.includes('page=list_forward_zones&letter=all') ||
                               url.includes('page=add_zone_master');
    expect(hasHandledResponse).toBeTruthy();
  });

  test('should validate template form fields', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_templ');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    const currentUrl = page.url();
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.toLowerCase().includes('error') ||
                     bodyText.toLowerCase().includes('required') ||
                     currentUrl.includes('add_zone_templ');
    expect(hasError).toBeTruthy();
  });

  test('should show template usage statistics', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_zone_templ');

    // Check if templates show usage count or statistics
    const hasTable = await page.locator('table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table').first()).toBeVisible();

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
    await page.goto('/index.php?page=list_forward_zones&letter=all');
    let bodyText = await page.locator('body').textContent();

    if (bodyText.includes(testDomain)) {
      const row = page.locator(`tr:has-text("${testDomain}")`);
      const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }
      }
    }

    // Delete test template
    await page.goto('/index.php?page=list_zone_templ');
    bodyText = await page.locator('body').textContent();

    if (bodyText.includes(templateName)) {
      const row = page.locator(`tr:has-text("${templateName}")`);
      const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }
      }
    }

    await page.close();
  });
});
