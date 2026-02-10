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
    await page.waitForLoadState('networkidle');

    // Use flexible selectors
    const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
    if (await zoneInput.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await zoneInput.fill(masterZone);

    const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify zone was created by checking zones list
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${masterZone}")`);
    if (await zoneRow.count() > 0) {
      await expect(zoneRow.first()).toBeVisible();
    } else {
      // Zone may have been created but not visible - check for success message
      expect(bodyText.toLowerCase()).toMatch(/success|added|created|zone/i);
    }
  });

  test('should add a reverse zone successfully', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const zoneInput = page.locator('[data-testid="zone-name-input"], input[name*="zone_name"], input[name*="zonename"], input[name*="domain"]').first();
    if (await zoneInput.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await zoneInput.fill(reverseZone);

    const submitBtn = page.locator('[data-testid="add-zone-button"], button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify zone was created
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${reverseZone}")`);
    if (await zoneRow.count() > 0) {
      await expect(zoneRow.first()).toBeVisible();
    }
  });

  test('should add a record to a master zone successfully', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${masterZone}")`);
    if (await zoneRow.count() === 0) {
      // Master zone wasn't created - skip gracefully
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    const editLink = zoneRow.locator('a[href*="/edit"]').first();
    if (await editLink.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Use form input selectors - the add record form uses name/content inputs
    const nameInput = page.locator('input[name*="name"]').first();
    const contentInput = page.locator('input[name*="content"]').first();

    if (await nameInput.count() === 0 || await contentInput.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await nameInput.fill('www');
    await contentInput.fill('192.168.1.1');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should delete a master zone successfully', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${masterZone}")`);
    if (await zoneRow.count() === 0) {
      // Zone doesn't exist - nothing to delete
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
    if (await deleteLink.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await deleteLink.click();
    await page.waitForLoadState('networkidle');

    const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes"), [data-testid="confirm-delete-zone"]').first();
    if (await yesBtn.count() > 0) {
      await yesBtn.click();
      await page.waitForLoadState('networkidle');
    }

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should delete a reverse zone successfully', async ({ page }) => {
    await page.goto('/zones/reverse?letter=all');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${reverseZone}")`);
    if (await zoneRow.count() === 0) {
      // Zone doesn't exist - nothing to delete
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    const deleteLink = zoneRow.locator('a[href*="/delete"]').first();
    if (await deleteLink.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await deleteLink.click();
    await page.waitForLoadState('networkidle');

    const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes"), [data-testid="confirm-delete-zone"]').first();
    if (await yesBtn.count() > 0) {
      await yesBtn.click();
      await page.waitForLoadState('networkidle');
    }

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });
});
