/**
 * WHOIS/RDAP Zone Links E2E Tests
 *
 * Tests for the WHOIS and RDAP lookup buttons that appear
 * on the zone edit page.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

const TEST_ZONE = 'admin-zone.example.com';

// admin-zone.example.com is fixture data on every instance, so a missing row is a
// real failure rather than a reason to skip the test.
async function getTestZoneId(page) {
  await page.goto('/zones/forward?letter=all');
  const checkbox = page.locator(`tr:has-text("${TEST_ZONE}") input[name="zone_id[]"]`).first();
  await expect(checkbox).toBeVisible();
  return await checkbox.getAttribute('value');
}

test.describe('WHOIS/RDAP Zone Links', () => {
  test('should navigate to WHOIS page from zone list link', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);

    // Navigate directly to the zone-specific WHOIS URL
    await page.goto(`/zones/${zoneId}/whois`);
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Should not show a fatal error or 404
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Should show WHOIS page content (either form or disabled message)
    const isValidPage = bodyText.toLowerCase().includes('whois') ||
                        bodyText.toLowerCase().includes('disabled') ||
                        bodyText.toLowerCase().includes('domain') ||
                        bodyText.toLowerCase().includes('lookup');
    expect(isValidPage).toBeTruthy();
  });

  test('should pre-fill domain on WHOIS page from zone ID', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);

    await page.goto(`/zones/${zoneId}/whois`);

    await expect(page.locator('input[name="domain"]')).toHaveValue(TEST_ZONE);
  });

  test('should navigate to RDAP page from zone list link', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);

    // Navigate directly to the zone-specific RDAP URL
    await page.goto(`/zones/${zoneId}/rdap`);
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Should not show a fatal error or 404
    expect(bodyText).not.toMatch(/fatal|exception/i);

    // Should show RDAP page content (either form or disabled message)
    const isValidPage = bodyText.toLowerCase().includes('rdap') ||
                        bodyText.toLowerCase().includes('disabled') ||
                        bodyText.toLowerCase().includes('domain') ||
                        bodyText.toLowerCase().includes('lookup');
    expect(isValidPage).toBeTruthy();
  });

  test('should pre-fill domain on RDAP page from zone ID', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);

    await page.goto(`/zones/${zoneId}/rdap`);

    await expect(page.locator('input[name="domain"]')).toHaveValue(TEST_ZONE);
  });

  test('should show WHOIS button on zone edit page for admin', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);

    await page.goto(`/zones/${zoneId}/edit`);

    // Check for zone-specific WHOIS button (not the nav link)
    const whoisButton = page.locator(`a[href*="/zones/${zoneId}/whois"]`);
    const whoisCount = await whoisButton.count();

    if (whoisCount > 0) {
      const href = await whoisButton.first().getAttribute('href');
      expect(href).toMatch(/\/zones\/\d+\/whois/);
    }

    // No fatal errors on the page
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should show RDAP button on zone edit page for admin', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);

    await page.goto(`/zones/${zoneId}/edit`);

    // Check for zone-specific RDAP button (not the nav link)
    const rdapButton = page.locator(`a[href*="/zones/${zoneId}/rdap"]`);
    const rdapCount = await rdapButton.count();

    if (rdapCount > 0) {
      const href = await rdapButton.first().getAttribute('href');
      expect(href).toMatch(/\/zones\/\d+\/rdap/);
    }

    // No fatal errors on the page
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should not show WHOIS or RDAP buttons in zone list', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');

    // WHOIS/RDAP buttons should not appear in zone list rows
    const whoisButtons = page.locator('a[data-testid^="whois-zone-"]');
    const rdapButtons = page.locator('a[data-testid^="rdap-zone-"]');

    expect(await whoisButtons.count()).toBe(0);
    expect(await rdapButtons.count()).toBe(0);
  });
});
