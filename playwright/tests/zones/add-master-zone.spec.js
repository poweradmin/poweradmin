import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Run tests serially as they depend on each other
test.describe.configure({ mode: 'serial' });

test.describe('Master Zone Management', () => {
  const timestamp = Date.now();
  const masterZone = `test-master-${timestamp}.example.com`;
  const reverseZone = `${timestamp % 256}.168.192.in-addr.arpa`;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should add a master zone successfully', async ({ page }) => {
    await page.goto('/zones/add/master');

    await page.locator('[data-testid="zone-name-input"]').fill(masterZone);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify zone was created by checking zones list
    await page.goto('/zones/forward?letter=all');
    await expect(page.locator(`tr:has-text("${masterZone}")`)).toBeVisible();
  });

  test('should add a reverse zone successfully', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(reverseZone);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify zone was created
    await page.goto('/zones/reverse?letter=all');
    await expect(page.locator(`tr:has-text("${reverseZone}")`)).toBeVisible();
  });

  test('should add a record to a master zone successfully', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${masterZone}")`).locator('a[href*="/edit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Use form input selectors - the add record form uses name/content inputs
    await page.locator('input[name*="name"]').first().fill('www');
    await page.locator('input[name*="content"]').first().fill('192.168.1.1');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText.toLowerCase()).toMatch(/success|added|record/i);
  });

  test('should delete a master zone successfully', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.locator(`tr:has-text("${masterZone}")`).locator('a[href*="/delete"]').first().click();

    const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes"), [data-testid="confirm-delete-zone"]').first();
    await yesBtn.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should delete a reverse zone successfully', async ({ page }) => {
    await page.goto('/zones/reverse?letter=all');
    await page.locator(`tr:has-text("${reverseZone}")`).locator('a[href*="/delete"]').first().click();

    const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes"), [data-testid="confirm-delete-zone"]').first();
    await yesBtn.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
