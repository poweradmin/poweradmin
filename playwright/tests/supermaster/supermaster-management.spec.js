import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Use serial mode since tests depend on created supermaster
test.describe.configure({ mode: 'serial' });

test.describe('Supermaster Management', () => {
  // A rerun must not collide with the supermaster a previous run left behind
  const stamp = Date.now();
  const octet = (shift) => Math.floor(stamp / shift) % 250;
  const testIp = `10.${octet(65536)}.${octet(256)}.${octet(1)}`;
  const testNameserver = `ns-test-${stamp}.example.com`;

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

  test('should have add button in card header', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    const addButton = page.locator('.card-header a[href*="supermasters/add"]');
    await expect(addButton).toBeVisible();
  });

  test('should have search input for filtering', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('#supermaster-search');
    // test-extra-data-*.sql seeds supermasters
    await expect(page.locator('.supermaster-row').first()).toBeVisible();
    await expect(searchInput).toBeVisible();

    // Type search and verify filtering
    const initialCount = await page.locator('.supermaster-row').count();
    await searchInput.fill('zzzznonexistent');
    const visibleAfter = await page.locator('.supermaster-row:visible').count();
    expect(visibleAfter).toBe(0);

    // Clear search
    await page.locator('#clear-supermaster-search').click();
    const visibleAfterClear = await page.locator('.supermaster-row:visible').count();
    expect(visibleAfterClear).toBe(initialCount);
  });

  test('should add a new supermaster', async ({ page }) => {
    // Navigate to add supermaster page
    await page.goto('/supermasters/add');
    await page.waitForLoadState('networkidle');

    await page.locator('#master_ip').fill(testIp);
    await page.locator('#ns_name').fill(testNameserver);

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    await expect(page).toHaveURL(/\/supermasters$/);
    await expect(page.locator('body')).toContainText('The supermaster has been added successfully.');
  });

  test('should list the created supermaster', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    const testRow = page.locator(`tr.supermaster-row:has-text("${testIp}")`);
    await expect(testRow).toHaveCount(1);
    await expect(testRow).toContainText(testNameserver);
  });

  test('should edit a supermaster', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    const testRow = page.locator(`tr.supermaster-row:has-text("${testIp}")`);
    await testRow.locator('a[href*="/supermasters/edit"]').click();

    // The edit form opens on the supermaster that was picked
    await expect(page.locator('#master_ip')).toHaveValue(testIp);
    await expect(page.locator('#ns_name')).toHaveValue(testNameserver);
  });

  test('should delete a supermaster', async ({ page }) => {
    await page.goto('/supermasters');
    await page.waitForLoadState('networkidle');

    const testRow = page.locator(`tr.supermaster-row:has-text("${testIp}")`);
    await testRow.locator('a[href*="/supermasters/delete"]').click();

    await page.locator('form[action*="/supermasters/delete"] button[type="submit"]').click();

    await expect(page.locator('body')).toContainText('The supermaster has been deleted successfully.');
    await expect(page.locator(`tr.supermaster-row:has-text("${testIp}")`)).toHaveCount(0);
  });

  test('should validate supermaster form', async ({ page }) => {
    await page.goto('/supermasters/add');
    await page.waitForLoadState('networkidle');

    // Submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Both fields are required, so the browser blocks the submit
    await expect(page.locator('form[action*="/supermasters/add"]')).toHaveClass(/was-validated/);
    await expect(page.locator('#master_ip')).toHaveValue('');
    await expect(page).toHaveURL(/\/supermasters\/add/);
  });
});
