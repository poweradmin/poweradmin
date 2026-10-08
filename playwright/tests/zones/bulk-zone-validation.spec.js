import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, findZoneIdByName, uniqueName, zoneExists } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Run serially to avoid race conditions with zone cleanup
test.describe.configure({ mode: 'serial' });

test.describe('Bulk Zone Registration Validation', () => {
  const timestamp = uniqueName('bulk');
  const created = [];

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  // Names a test may create; removed in afterEach even when the test failed
  function track(...names) {
    created.push(...names);
  }

  test.afterEach(async ({ page }) => {
    // Resolving the id first covers zones that the paginated list does not show on its first page
    for (const name of created.splice(0)) {
      const zoneId = await findZoneIdByName(page, name);
      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }
    }
  });

  test('should register single zone via bulk registration', async ({ page }) => {
    const zoneName = `bulktest1-${timestamp}.com`;
    track(zoneName);
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zoneName);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

    // Verify zone was created by checking zones list
    expect(await zoneExists(page, zoneName)).toBe(true);

  });

  test('should register multiple zones via bulk registration', async ({ page }) => {
    test.slow();
    const zones = [`bulktest1-${timestamp}.com`, `bulktest2-${timestamp}.org`, `bulktest3-${timestamp}.net`];
    track(...zones);
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zones.join('\n'));
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

    // Verify zones were created
    for (const zone of zones) {
      expect(await zoneExists(page, zone)).toBe(true);
    }

  });

  test('should handle zone with non-standard TLD', async ({ page }) => {
    const zoneName = `invalidzone-${timestamp}.invalidtld123`;
    track(zoneName);
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zoneName);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Application may allow any TLD - check that page processed without fatal error
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

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
    track(validZone, invalidZone);
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(`${validZone}\n${invalidZone}`);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Application may process all zones or show partial results
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

  });

  test('should validate zone name format', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill('invalid_zone!@#.com');
    await page.locator('button[type="submit"], input[type="submit"]').click();

    await expect(page.locator('body')).toContainText(/error|invalid|failed/i);
  });

  test('should prevent duplicate zone registration', async ({ page }) => {
    const zoneName = `duplicate-test-${timestamp}.com`;
    track(zoneName);

    // First, create a zone via direct navigation
    await page.goto('/zones/add/master');
    await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Try to add same zone via bulk registration
    await page.goto('/zones/bulk-registration');
    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zoneName);
    await page.locator('button[type="submit"], input[type="submit"]').click();

    // Match various duplicate zone error messages: "already exists", "already a zone", "duplicate", etc.
    // Auto-retrying assertion: the submit navigation may still be in flight.
    await expect(page.locator('body')).toContainText(/already|duplicate|error/i);

  });

  test('should handle empty bulk registration submission', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    await page.locator('button[type="submit"], input[type="submit"]').click();

    const currentUrl = page.url();
    expect(currentUrl).toMatch(/bulk|registration/i);
  });

  test('should trim whitespace from zone names', async ({ page }) => {
    const zoneName = `whitespace-test-${timestamp}.com`;
    track(zoneName);
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(`  ${zoneName}  `);
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    expect(await zoneExists(page, zoneName), `zone ${zoneName} must be created without the padding`).toBe(true);
  });

  test('should handle zones with various valid TLDs', async ({ page }) => {
    test.slow();
    const zones = [
      `tldtest1-${timestamp}.com`,
      `tldtest2-${timestamp}.net`,
      `tldtest3-${timestamp}.org`,
      `tldtest4-${timestamp}.io`,
      `tldtest5-${timestamp}.dev`
    ];
    track(...zones);
    await page.goto('/zones/bulk-registration');

    await page.locator('textarea[name*="domain"], textarea[name*="zone"], textarea').fill(zones.join('\n'));
    await page.locator('button[type="submit"], input[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    for (const zone of zones) {
      expect(await zoneExists(page, zone), `zone ${zone} must be created`).toBe(true);
    }
  });
});
