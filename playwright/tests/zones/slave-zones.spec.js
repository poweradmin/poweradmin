import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Slave Zones Management', () => {
  test('should access add slave zone page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_slave');
    await expect(page).toHaveURL(/page=add_zone_slave/);
    await expect(page.locator('form')).toBeVisible();
  });

  test('should show slave zone form fields', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_slave');

    // Should have zone name field
    await expect(
      page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]').first()
    ).toBeVisible();

    // Should have master server field
    await expect(
      page.locator('input[name*="master"], input[name*="server"], textarea[name*="master"]').first()
    ).toBeVisible();
  });

  test('should validate slave zone creation form', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_slave');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation errors or stay on form
    await expect(page).toHaveURL(/page=add_zone_slave/);
  });

  test('should require master server for slave zone', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_zone_slave');

    // Fill zone name but leave master empty
    await page.locator('input[name*="zone"], input[name*="domain"], input[name*="name"]')
      .first()
      .fill('test-slave.example.com');

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Validation should either:
    // 1. Keep the form on add_zone_slave page, OR
    // 2. Show an explicit error message
    const currentUrl = page.url();
    const stayedOnForm = currentUrl.includes('add_zone_slave');

    const bodyText = await page.locator('body').textContent();
    const hasValidationError = bodyText.toLowerCase().includes('error') ||
                               bodyText.toLowerCase().includes('required') ||
                               bodyText.toLowerCase().includes('invalid') ||
                               bodyText.toLowerCase().includes('please enter') ||
                               bodyText.toLowerCase().includes('must specify');

    // Test fails if form was submitted successfully (navigated away without error)
    expect(stayedOnForm || hasValidationError).toBeTruthy();

    // If we navigated away, there must be an error message
    if (!stayedOnForm) {
      expect(hasValidationError).toBeTruthy();
    }
  });
});
