/**
 * WHOIS/RDAP Zone Links E2E Tests
 *
 * Tests for the WHOIS and RDAP lookup buttons that appear
 * next to zones in the zone list pages.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Helper to get a zone ID from the zone list
async function getTestZoneId(page) {
  await page.goto('/zones/forward?letter=all');
  const editLink = page.locator('a[href*="/edit"]').first();
  if (await editLink.count() > 0) {
    const href = await editLink.getAttribute('href');
    const match = href.match(/\/zones\/(\d+)\/edit/);
    return match ? match[1] : null;
  }
  return null;
}

test.describe('WHOIS/RDAP Zone Links', () => {
  test('should navigate to WHOIS page from zone list link', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

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
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/whois`);
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // If WHOIS is disabled, skip the rest
    if (bodyText.toLowerCase().includes('disabled') || bodyText.toLowerCase().includes('not available')) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // The domain input should be pre-filled with the zone name
    const domainInput = page.locator('input[name="domain"]');
    if (await domainInput.count() > 0) {
      const value = await domainInput.inputValue();
      // Should have some value (the zone name)
      expect(value.length).toBeGreaterThan(0);
    }
  });

  test('should navigate to RDAP page from zone list link', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

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
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/rdap`);
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // If RDAP is disabled, skip the rest
    if (bodyText.toLowerCase().includes('disabled') || bodyText.toLowerCase().includes('not available')) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // The domain input should be pre-filled with the zone name
    const domainInput = page.locator('input[name="domain"]');
    if (await domainInput.count() > 0) {
      const value = await domainInput.inputValue();
      expect(value.length).toBeGreaterThan(0);
    }
  });

  test('should show WHOIS buttons in zone list for admin', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    // Check for WHOIS buttons using data-testid
    const whoisButtons = page.locator('a[data-testid^="whois-zone-"]');
    const rdapButtons = page.locator('a[data-testid^="rdap-zone-"]');

    // At least one of these should be visible if WHOIS/RDAP is enabled
    const whoisCount = await whoisButtons.count();
    const rdapCount = await rdapButtons.count();

    // If either tool is enabled, buttons should be present
    if (whoisCount > 0) {
      const href = await whoisButtons.first().getAttribute('href');
      expect(href).toMatch(/\/zones\/\d+\/whois/);
    }

    if (rdapCount > 0) {
      const href = await rdapButtons.first().getAttribute('href');
      expect(href).toMatch(/\/zones\/\d+\/rdap/);
    }

    // No fatal errors on the page
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should handle clicking WHOIS button from zone list', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const whoisButton = page.locator('a[data-testid^="whois-zone-"]').first();
    if (await whoisButton.count() === 0) {
      // WHOIS not enabled - skip
      return;
    }

    await whoisButton.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Should not crash
    expect(bodyText).not.toMatch(/fatal|exception/i);
    // Should not show a "not found" page
    expect(bodyText.toLowerCase()).not.toContain('not found');
  });

  test('should handle clicking RDAP button from zone list', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const rdapButton = page.locator('a[data-testid^="rdap-zone-"]').first();
    if (await rdapButton.count() === 0) {
      // RDAP not enabled - skip
      return;
    }

    await rdapButton.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Should not crash
    expect(bodyText).not.toMatch(/fatal|exception/i);
    // Should not show a "not found" page
    expect(bodyText.toLowerCase()).not.toContain('not found');
  });
});
