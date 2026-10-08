import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Use serial mode since tests depend on each other
test.describe.configure({ mode: 'serial' });

test.describe('Complete Domain Management Workflow', () => {
  const testDomain = `test-domain-${Date.now()}.com`;
  let zoneId = null;

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should complete full domain creation workflow', async ({ page }) => {
    // Step 1: Navigate to add master zone
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/.*zones\/add\/master/);

    // Step 2: Fill in domain details
    await page.locator('[data-testid="zone-name-input"]').fill(testDomain);

    // Step 3: Submit the form
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

    // Step 4: Verify the zone exists
    zoneId = await findZoneIdByName(page, testDomain);
    expect(zoneId, `zone ${testDomain} must be created`).toBeTruthy();
  });

  test('should add essential DNS records to the domain', async ({ page }) => {
    expect(zoneId, 'the zone created by the first test').toBeTruthy();

    // Add A record for www through the form at the top of the zone edit page
    await page.goto(`/zones/${zoneId}/edit`);
    await page.locator('#recordTypeSelectTop').selectOption('A');
    await page.locator('input[name="name"]').first().fill('www');
    await page.locator('#recordContentTop').fill('192.168.1.100');
    await page.getByRole('button', { name: 'Add record' }).click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

    await page.goto(`/zones/${zoneId}/edit`);
    await expect(page.locator('input[value="192.168.1.100"]')).toHaveCount(1);
  });

  test('should verify domain resolution and records', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    // The first test in this serial file creates testDomain, so its row is there
    const domainRow = page.locator(`tr:has-text("${testDomain}")`).first();
    await expect(domainRow).toBeVisible();

    await domainRow.locator('a[href*="edit"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await expect(page.locator('body')).toContainText(testDomain);
  });

  test('should handle domain search functionality', async ({ page }) => {
    await page.goto('/search');
    await page.waitForLoadState('networkidle');

    // Search for our test domain
    const searchInput = page.locator('input[name="query"], input[type="search"], input[placeholder*="search"]').first();
    await searchInput.fill(testDomain);

    const submitBtn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Search")').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    // The first test in this serial file creates testDomain, so search must find it
    await expect(page.locator(`table tr:has-text("${testDomain}")`).first()).toBeVisible();
  });

  // Cleanup: Delete the test domain
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const id = zoneId ?? await findZoneIdByName(page, testDomain);
      if (id) {
        await deleteZoneById(page, id);
      }
    } catch {
      // Ignore cleanup errors
    }

    await page.close();
  });
});
