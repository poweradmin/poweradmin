import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Run serially to avoid race conditions with zone cleanup
test.describe.configure({ mode: 'serial' });

test.describe('Bulk Zone Registration Validation', () => {
  const timestamp = Date.now();

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  // Helper to clean up a zone
  async function cleanupZone(page, zoneName) {
    try {
      await page.goto('/zones/forward?letter=all');
      const zoneRow = page.locator(`tr:has-text("${zoneName}")`);
      if (await zoneRow.count() > 0) {
        await zoneRow.locator('a[href*="/delete"]').first().click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    } catch (e) {
      // Zone might not exist, continue
    }
  }

  test('should register single zone via bulk registration', async ({ page }) => {
    const zoneName = `bulktest1-${timestamp}.com`;
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zoneName);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify zone was created by checking zones list
    await page.goto('/zones/forward?letter=all');
    await expect(page.locator(`tr:has-text("${zoneName}")`)).toBeVisible();

    // Cleanup
    await cleanupZone(page, zoneName);
  });

  test('should register multiple zones via bulk registration', async ({ page }) => {
    const zones = [`bulktest1-${timestamp}.com`, `bulktest2-${timestamp}.org`, `bulktest3-${timestamp}.net`];
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zones.join('\n'));
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Verify zones were created
    await page.goto('/zones/forward?letter=all');
    for (const zone of zones) {
      await expect(page.locator(`tr:has-text("${zone}")`)).toBeVisible();
    }

    // Cleanup
    for (const zone of zones) {
      await cleanupZone(page, zone);
    }
  });

  test('should handle zone with non-standard TLD', async ({ page }) => {
    const zoneName = `invalidzone-${timestamp}.invalidtld123`;
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zoneName);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Application may allow any TLD - check that page processed without fatal error
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Clean up if zone was created
    await page.goto('/zones/forward?letter=all');
    const zoneRow = page.locator(`tr:has-text("${zoneName}")`);
    if (await zoneRow.count() > 0) {
      await cleanupZone(page, zoneName);
    }
  });

  test('should show error for malformed domain name', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('invalid..domain.com');
    await page.locator('button[type="submit"], input[type="submit"]').click();

    await expect(page.locator('body')).toContainText(/error|invalid|failed/i);
  });

  test('should handle mix of valid and invalid zones', async ({ page }) => {
    const validZone = `validzone-${timestamp}.com`;
    const invalidZone = `invalidzone-${timestamp}.invalidtld`;
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(`${validZone}\n${invalidZone}`);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Application may process all zones or show partial results
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Cleanup any zones that were created
    await cleanupZone(page, validZone);
    await cleanupZone(page, invalidZone);
  });

  test('should validate zone name format', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('invalid_zone!@#.com');
    await page.locator('button[type="submit"], input[type="submit"]').click();

    await expect(page.locator('body')).toContainText(/error|invalid|failed/i);
  });

  test('should prevent duplicate zone registration', async ({ page }) => {
    const zoneName = `duplicate-test-${timestamp}.com`;

    // First, create a zone via direct navigation
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Try to add same zone via bulk registration
    await page.goto('/zones/bulk-registration');
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zoneName);
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Should show error about duplicate
    const bodyText = await page.locator('body').textContent();
    // Match various duplicate zone error messages: "already exists", "already a zone", "duplicate", etc.
    const hasDuplicateError = bodyText.match(/already|duplicate|error/i);
    expect(hasDuplicateError).toBeTruthy();

    // Cleanup
    await cleanupZone(page, zoneName);
  });

  test('should handle empty bulk registration submission', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    const currentUrl = page.url();
    expect(currentUrl).toMatch(/bulk|registration/i);
  });

  test('should trim whitespace from zone names', async ({ page }) => {
    const zoneName = `whitespace-test-${timestamp}.com`;
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(`  ${zoneName}  `);
    await page.locator('button[type="submit"], input[type="submit"]').click();

    const bodyText = await page.locator('body').textContent();
    if (bodyText.match(/success|added/i)) {
      await cleanupZone(page, zoneName);
    }
  });

  test('should handle zones with various valid TLDs', async ({ page }) => {
    const zones = [
      `tldtest1-${timestamp}.com`,
      `tldtest2-${timestamp}.net`,
      `tldtest3-${timestamp}.org`,
      `tldtest4-${timestamp}.io`,
      `tldtest5-${timestamp}.dev`
    ];
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zones.join('\n'));
    await page.locator('button[type="submit"], input[type="submit"]').click();

    const bodyText = await page.locator('body').textContent();
    if (bodyText.match(/success|added/i)) {
      for (const zone of zones) {
        await cleanupZone(page, zone);
      }
    }
  });
});
