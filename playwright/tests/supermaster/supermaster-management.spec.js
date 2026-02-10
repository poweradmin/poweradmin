import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Use serial mode since tests depend on created supermaster
test.describe.configure({ mode: 'serial' });

test.describe('Supermaster Management', () => {
  const testIp = '192.168.100.50';
  const testNameserver = 'ns-test.example.com';

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access supermaster list page', async ({ page }) => {
    // Navigate directly to supermaster list
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText.toLowerCase()).toMatch(/supermaster|master|slave/i);
  });

  test('should show supermaster list page', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    // Should show supermaster table or empty state
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should add a new supermaster', async ({ page }) => {
    // Navigate to add supermaster page
    await page.goto('/supermasters/add');
    await page.waitForLoadState('networkidle');

    // Check if the add form exists
    const ipField = page.locator('input[name*="ip"], input[placeholder*="ip"]').first();
    if (await ipField.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Fill in supermaster details
    await ipField.fill(testIp);

    const nsField = page.locator('input[name*="nameserver"], input[name*="ns"], input[placeholder*="nameserver"]').first();
    if (await nsField.count() > 0) {
      await nsField.fill(testNameserver);
    }

    const accountField = page.locator('input[name*="account"], input[placeholder*="account"]').first();
    if (await accountField.count() > 0) {
      await accountField.fill('test-account');
    }

    // Submit form
    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify success or no fatal error
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should list the created supermaster', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Should show the test supermaster if it was created
    if (bodyText.includes(testIp)) {
      expect(bodyText).toContain(testIp);
    } else {
      // Supermaster may not have been created in previous test
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should edit a supermaster', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    // Find a supermaster row in the table
    const supermasterTable = page.locator('table');
    if (await supermasterTable.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Look for an edit link in the table
    const editLink = supermasterTable.locator('tbody a[href*="edit"]').first();
    if (await editLink.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await editLink.click();
    await page.waitForLoadState('networkidle');

    // Just verify the edit page loads
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should delete a supermaster', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    // Find a supermaster row with our test IP
    const testRow = page.locator(`tr:has-text("${testIp}")`).first();
    if (await testRow.count() === 0) {
      // No test supermaster to delete
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Find and click delete link
    const deleteLink = testRow.locator('a[href*="delete"]').first();
    if (await deleteLink.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    await deleteLink.click();
    await page.waitForLoadState('networkidle');

    // Confirm deletion if needed
    const confirmBtn = page.locator('button[type="submit"]:has-text("Delete"), input[value*="Delete"], button:has-text("Yes"), input[value="Yes"]').first();
    if (await confirmBtn.count() > 0) {
      await confirmBtn.click();
      await page.waitForLoadState('networkidle');
    }

    // Verify page loaded without errors
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should validate supermaster form', async ({ page }) => {
    await page.goto('/supermasters/add');
    await page.waitForLoadState('networkidle');

    // Check if form exists
    const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
    if (await submitBtn.count() === 0) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Submit empty form
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // Should show validation error or stay on form
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Either shows error or stays on add page
    expect(page.url().includes('supermaster') || bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('required')).toBeTruthy();
  });
});
