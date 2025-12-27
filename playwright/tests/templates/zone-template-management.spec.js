import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Template Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should list zone templates', async ({ page }) => {
    await page.locator('[data-testid="zone-templ-link"]').click();
    await expect(page).toHaveURL(/.*zones\/templates/);
    await expect(page.locator('[data-testid="zone-templates-table"]')).toBeVisible();
  });

  test('should add a new zone template', async ({ page }) => {
    await page.locator('[data-testid="zone-templ-link"]').click();
    await page.locator('[data-testid="add-zone-templ-link"]').click();

    // Fill template form
    await page.locator('[data-testid="zone-templ-name-input"]').fill('Playwright Test Template');
    await page.locator('[data-testid="zone-templ-desc-input"]').fill('Template created by Playwright tests');

    // Submit form
    await page.locator('[data-testid="add-zone-templ-button"]').click();

    // Verify success
    await expect(page.locator('[data-testid="alert-message"]')).toContainText('The zone template has been added successfully.');
  });

  test('should add records to a zone template', async ({ page }) => {
    await page.locator('[data-testid="zone-templ-link"]').click();

    // Find and select the template we created
    await page.locator('tr:has-text("Playwright Test Template")').locator('[data-testid^="edit-zone-templ-"]').click();

    // Add A record to template
    await page.locator('[data-testid="add-zone-templ-record-link"]').click();
    await page.locator('[data-testid="record-type-select"]').selectOption('A');
    await page.locator('[data-testid="record-name-input"]').fill('www');
    await page.locator('[data-testid="record-content-input"]').fill('192.168.1.10');
    await page.locator('[data-testid="record-ttl-input"]').clear();
    await page.locator('[data-testid="record-ttl-input"]').fill('3600');
    await page.locator('[data-testid="add-templ-record-button"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('The record was successfully added to the template.');

    // Add MX record to template
    await page.locator('[data-testid="add-zone-templ-record-link"]').click();
    await page.locator('[data-testid="record-type-select"]').selectOption('MX');
    await page.locator('[data-testid="record-name-input"]').fill('@');
    await page.locator('[data-testid="record-content-input"]').fill('mail.$DOMAIN');
    await page.locator('[data-testid="record-ttl-input"]').clear();
    await page.locator('[data-testid="record-ttl-input"]').fill('3600');
    await page.locator('[data-testid="record-prio-input"]').clear();
    await page.locator('[data-testid="record-prio-input"]').fill('10');
    await page.locator('[data-testid="add-templ-record-button"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('The record was successfully added to the template.');
  });

  test('should apply a zone template when creating a zone', async ({ page }) => {
    // Create a new zone with template
    await page.locator('[data-testid="add-master-zone-link"]').click();
    await page.locator('[data-testid="zone-name-input"]').fill('template-test.com');
    await page.locator('[data-testid="zone-template-select"]').selectOption('Playwright Test Template');
    await page.locator('[data-testid="add-zone-button"]').click();

    // Verify zone creation
    await expect(page.locator('[data-testid="alert-message"]')).toContainText('Zone has been added successfully.');

    // Check that template records were applied
    await page.locator('tr:has-text("template-test.com")').locator('[data-testid^="edit-zone-"]').click();

    await expect(page.locator('td').filter({ hasText: 'www' })).toBeVisible();
    await expect(page.locator('td').filter({ hasText: '192.168.1.10' })).toBeVisible();
    await expect(page.locator('td').filter({ hasText: 'mail.template-test.com' })).toBeVisible();
  });

  test('should edit a zone template', async ({ page }) => {
    await page.locator('[data-testid="zone-templ-link"]').click();

    // Find and edit the template
    await page.locator('tr:has-text("Playwright Test Template")').locator('[data-testid^="edit-zone-templ-"]').click();

    // Edit template details
    await page.locator('[data-testid="edit-zone-templ-link"]').click();
    await page.locator('[data-testid="zone-templ-desc-input"]').clear();
    await page.locator('[data-testid="zone-templ-desc-input"]').fill('Updated template description');
    await page.locator('[data-testid="update-zone-templ-button"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('The zone template has been updated successfully.');
  });

  test('should delete a zone template', async ({ page }) => {
    // First delete the test zone that uses the template
    await page.locator('[data-testid="list-zones-link"]').click();
    await page.locator('tr:has-text("template-test.com")').locator('[data-testid^="delete-zone-"]').click();
    await page.locator('[data-testid="confirm-delete-zone"]').click();

    // Now delete the template
    await page.locator('[data-testid="zone-templ-link"]').click();
    await page.locator('tr:has-text("Playwright Test Template")').locator('[data-testid^="delete-zone-templ-"]').click();
    await page.locator('[data-testid="confirm-delete-zone-templ"]').click();

    await expect(page.locator('[data-testid="alert-message"]')).toContainText('The zone template has been deleted successfully.');
  });
});
