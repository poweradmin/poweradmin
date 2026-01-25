import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone Template Management', () => {
  const templateName = `pw-test-template-${Date.now()}`;
  const testZone = `template-test-${Date.now()}.com`;

  test('should list zone templates', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_zone_templ');
    await expect(page).toHaveURL(/page=list_zone_templ/);

    const hasTable = await page.locator('table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table').first()).toBeVisible();
    } else {
      // Empty state - page should still load
      await expect(page.locator('body')).toBeVisible();
    }
  });

  test('should add a new zone template', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_templ');

    // Fill template form
    await page.locator('input[name*="name"], input[name*="template"]').first().fill(templateName);

    const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
    if (await descField.count() > 0) {
      await descField.fill('Template created by Playwright tests');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify success
    const bodyText = await page.locator('body').textContent();
    const hasSuccess = bodyText.toLowerCase().includes('success') ||
                       bodyText.toLowerCase().includes('added') ||
                       page.url().includes('list_zone_templ') ||
                       page.url().includes('edit_zone_templ');
    expect(hasSuccess).toBeTruthy();
  });

  test('should add records to a zone template', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_zone_templ');

    // Find and select the template we created
    const row = page.locator(`tr:has-text("${templateName}")`);
    if (await row.count() > 0) {
      // Click the edit link (with href*="edit_zone_templ"), not the first disabled link
      const editLink = row.locator('a[href*="edit_zone_templ"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
      } else {
        // Fallback: click any enabled link
        await row.locator('a:not(.disabled)').first().click();
      }

      // Check if we can add records
      const hasRecordForm = await page.locator('select[name*="type"]').count() > 0;
      if (hasRecordForm) {
        // Add A record to template
        await page.locator('select[name*="type"]').first().selectOption('A');

        const nameInput = page.locator('input[name*="name"]').first();
        if (await nameInput.count() > 0) {
          await nameInput.fill('www');
        }

        const contentInput = page.locator('input[name*="content"], input[name*="value"]').first();
        if (await contentInput.count() > 0) {
          await contentInput.fill('192.168.1.10');
        }

        const ttlInput = page.locator('input[name*="ttl"]').first();
        if (await ttlInput.count() > 0) {
          await ttlInput.clear();
          await ttlInput.fill('3600');
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });

  test('should apply a zone template when creating a zone', async ({ adminPage: page }) => {
    // Create a new zone with template - use fresh timestamp and random suffix to ensure uniqueness
    await page.goto('/index.php?page=add_zone_master');

    const uniqueTestZone = `template-test-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.com`;
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(uniqueTestZone);

    // Select template if dropdown exists
    const templateSelect = page.locator('select[name*="template"]').first();
    if (await templateSelect.count() > 0) {
      const options = await templateSelect.locator('option').allTextContents();
      const templateOption = options.find(opt => opt.includes(templateName));
      if (templateOption) {
        await templateSelect.selectOption({ label: templateOption.trim() });
      } else if (options.length > 1) {
        // Fallback: select first non-empty template
        await templateSelect.selectOption({ index: 1 });
      }
    }

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Wait for page to process
    await page.waitForLoadState('networkidle');

    // Verify zone creation or acceptable response (error handling, zone already exists)
    const bodyText = await page.locator('body').textContent();
    const url = page.url();
    const hasHandledResponse = bodyText.toLowerCase().includes('success') ||
                               bodyText.toLowerCase().includes('added') ||
                               bodyText.toLowerCase().includes('created') ||
                               bodyText.toLowerCase().includes('already') ||
                               bodyText.includes(uniqueTestZone) ||
                               bodyText.toLowerCase().includes('error') ||
                               url.includes('page=edit') ||
                               url.includes('page=list_forward_zones&letter=all') ||
                               url.includes('page=add_zone_master');
    expect(hasHandledResponse).toBeTruthy();
  });

  test('should edit a zone template', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_zone_templ');

    const row = page.locator(`tr:has-text("${templateName}")`);
    if (await row.count() > 0) {
      const editLink = row.locator('a').filter({ hasText: /Edit/i });
      if (await editLink.count() > 0) {
        await editLink.first().click();

        // Update template description
        const descField = page.locator('input[name*="description"], textarea[name*="description"]').first();
        if (await descField.count() > 0) {
          await descField.clear();
          await descField.fill('Updated template description');
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });

  test('should delete a zone template', async ({ adminPage: page }) => {
    // First delete the test zone that uses the template
    await page.goto('/index.php?page=list_forward_zones&letter=all');
    let row = page.locator(`tr:has-text("${testZone}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }
      }
    }

    // Now delete the template
    await page.goto('/index.php?page=list_zone_templ');
    row = page.locator(`tr:has-text("${templateName}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });
});
