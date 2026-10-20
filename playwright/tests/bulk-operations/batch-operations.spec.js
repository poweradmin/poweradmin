import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, errorMessages, findZoneIdByName, openZoneListPageFor, uniqueName, zoneExists } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('Bulk and Batch Operations', () => {
  // Registration, listing and deletion share one set of throwaway zones, so run in order
  test.describe.configure({ mode: 'serial' });

  // Evaluated per worker; every name is unique so reruns and parallel files never collide
  const baseTestDomain = uniqueName('bulk');
  const testDomains = [
    `${baseTestDomain}-1.example.com`,
    `${baseTestDomain}-2.example.com`,
    `${baseTestDomain}-3.example.com`
  ];
  const progressBase = uniqueName('bulkp');

  const progressDomains = [];

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access bulk registration page', async ({ page }) => {
    await page.goto('/zones/bulk-registration');
    await expect(page).toHaveURL(/.*zones\/bulk-registration/);
    await expect(page.locator('h1, h2, h3, .page-title, form').first()).toBeVisible();
  });

  test('should perform bulk domain registration', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    // Enter multiple domains for bulk registration
    await page.locator('textarea[name="domains"]').fill(testDomains.join('\n'));
    await page.locator('button[name="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Verify bulk registration success
    await expect(page.locator('body')).toContainText(/success|created|registered/i);
  });

  test('should verify bulk registered domains exist', async ({ page }) => {
    // The zones this suite registered must be findable in the list
    for (const domain of testDomains) {
      expect(await zoneExists(page, domain)).toBe(true);
    }
  });

  test('should access batch PTR record generation', async ({ page }) => {
    await page.goto('/zones/batch-ptr');
    await expect(page).toHaveURL(/.*zones\/batch-ptr/);
    await expect(page.locator('h1, h2, h3, .page-title, form').first()).toBeVisible();
  });

  test('should refuse an invalid batch PTR network prefix', async ({ page }) => {
    await page.goto('/zones/batch-ptr');

    await expect(page.locator('#network_prefix')).toBeVisible();
    await expect(page.locator('#domain')).toBeVisible();

    // The IPv6 count is only shown for the IPv6 network type
    await page.locator('#network_type').selectOption('ipv6');
    await expect(page.locator('#ipv6_count')).toBeVisible();
    await page.locator('#network_type').selectOption('ipv4');

    await page.locator('#network_prefix').fill('not-a-prefix');
    await page.locator('#domain').fill('example.com');
    await page.locator('form button[type="submit"]').first().click();

    // The form is shown again with the entered prefix and nothing was generated
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await expect(page).toHaveURL(/\/zones\/batch-ptr/);
    await expect(page.locator('#network_prefix')).toHaveValue('not-a-prefix');
  });

  test('should perform bulk zone deletion', async ({ page }) => {
    // Walking the paginated list and confirming the deletion needs more than
    // the default per-test budget on a busy instance.
    test.slow();

    // The list is paginated, so walk to the page that holds the test domains
    expect(await openZoneListPageFor(page, testDomains[0]), 'registered domains must be listed').toBe(true);

    // Select test domains for bulk deletion
    for (const domain of testDomains) {
      const domainCheckbox = page.locator(`tr:has-text("${domain}")`).locator('input[type="checkbox"]');
      await expect(domainCheckbox).toBeVisible();
      await domainCheckbox.check();
    }

    // The bulk submit is disabled until a row is ticked
    const bulkDeleteBtn = page.locator('#delete-zones-btn');
    await expect(bulkDeleteBtn).toBeEnabled();
    await bulkDeleteBtn.click();

    // Confirm bulk deletion on the confirmation page, which only exists once
    // the navigation has landed
    const confirmBtn = page.locator('button[type="submit"][name="confirm"]');
    await expect(confirmBtn).toBeVisible();
    await confirmBtn.click();
    await page.waitForLoadState('networkidle');

    // Verify the domains are really gone, not just off the current page
    for (const domain of testDomains) {
      expect(await zoneExists(page, domain)).toBe(false);
    }
  });

  test('should handle bulk operations with validation errors', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    // A bare label such as "invalid-domain" is a valid zone name, so it is not used here
    const invalidDomains = ['..invalid..', '-invalid-.example.com', 'bad!name.example.com'];
    await page.locator('textarea[name="domains"]').fill(invalidDomains.join('\n'));
    await page.locator('button[name="submit"]').click();

    // The echoed textarea also contains "invalid", so read the flash message only
    await expect(page.locator('[data-testid="system-message"]').first()).toBeVisible();
    expect(await errorMessages(page), 'a refusal message must be shown').not.toBe('');

    for (const domain of invalidDomains) {
      expect(await zoneExists(page, domain), `${domain} must not be created`).toBe(false);
    }
  });

  test('should show bulk operation progress and results', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    progressDomains.push(`${progressBase}-1.example.com`, `${progressBase}-2.example.com`);
    await page.locator('textarea[name="domains"]').fill(progressDomains.join('\n'));
    await page.locator('button[name="submit"]').click();

    // Both domains are valid, so the result must report them and they must exist
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await expect(page.locator('body')).toContainText(/success|created|registered/i);
    for (const domain of progressDomains) {
      expect(await zoneExists(page, domain), `${domain} must be registered`).toBe(true);
    }
  });

  test('should export bulk zone data', async ({ page }) => {
    // Zone data is exported per zone from the edit page
    const zoneId = await findZoneIdByName(page, 'admin-zone.example.com');
    expect(zoneId, 'admin-zone.example.com must be seeded').toBeTruthy();

    await page.goto(`/zones/${zoneId}/edit`);
    await page.locator('button.dropdown-toggle:has-text("Export")').click();
    await expect(page.locator('.dropdown-menu a[href*="/export/csv"]')).toBeVisible();
  });

  // Cleanup any remaining test domains
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    for (const domain of [...testDomains, ...progressDomains]) {
      const zoneId = await findZoneIdByName(page, domain);
      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }
    }

    await page.close();
  });
});
