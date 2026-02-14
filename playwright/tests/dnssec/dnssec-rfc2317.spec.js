/**
 * DNSSEC RFC 2317 Classless Reverse Zone Tests
 *
 * Tests that DNSSEC signing works correctly for RFC 2317 zones
 * whose names contain a forward slash (e.g. 0/26.1.168.192.in-addr.arpa).
 *
 * @see https://github.com/poweradmin/poweradmin/issues/994
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { findZoneIdByName, createZone } from '../../helpers/zones.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNSSEC for RFC 2317 Classless Reverse Zones', () => {
  const rfc2317Zone = '0/26.1.168.192.in-addr.arpa';
  let zoneId = null;

  test('should create an RFC 2317 classless reverse zone', async ({ adminPage: page }) => {
    zoneId = await findZoneIdByName(page, rfc2317Zone);

    if (!zoneId) {
      zoneId = await createZone(page, rfc2317Zone, 'master');
    }

    expect(zoneId).toBeTruthy();
  });

  test('should sign RFC 2317 zone without API error', async ({ adminPage: page }) => {
    if (!zoneId) {
      zoneId = await findZoneIdByName(page, rfc2317Zone);
    }

    if (!zoneId) {
      test.skip('RFC 2317 zone not available');
      return;
    }

    await page.goto(`/zones/${zoneId}/edit`);

    const signButton = page.locator('button[name="sign_zone"]');
    if (await signButton.count() === 0) {
      // Zone may already be signed or DNSSEC not enabled on server
      const bodyText = await page.locator('body').textContent();
      if (bodyText.includes('DNSSEC')) {
        test.info().annotations.push({ type: 'note', description: 'Zone already signed or DNSSEC link present' });
        return;
      }
      test.skip('Sign zone button not available - DNSSEC may not be enabled on server');
      return;
    }

    await signButton.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Should NOT show the API error from issue #994
    expect(bodyText).not.toContain('PowerDNS API returned an error');
    expect(bodyText).not.toContain('Failed to sign zone');
  });

  test('should access DNSSEC management page for signed RFC 2317 zone', async ({ adminPage: page }) => {
    if (!zoneId) {
      zoneId = await findZoneIdByName(page, rfc2317Zone);
    }

    if (!zoneId) {
      test.skip('RFC 2317 zone not available');
      return;
    }

    await page.goto(`/zones/${zoneId}/dnssec`);

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText.toLowerCase()).toMatch(/dnssec|key|secure/i);
  });

  test('should unsign and delete RFC 2317 zone', async ({ adminPage: page }) => {
    if (!zoneId) {
      zoneId = await findZoneIdByName(page, rfc2317Zone);
    }

    if (!zoneId) {
      test.skip('RFC 2317 zone not available for cleanup');
      return;
    }

    // Navigate to DNSSEC page to unsign
    await page.goto(`/zones/${zoneId}/dnssec`);

    // The "Unsign zone" toolbar button opens a Bootstrap modal
    const unsignTrigger = page.locator('button[data-bs-target="#unsignZoneModal"]');
    if (await unsignTrigger.count() > 0) {
      await unsignTrigger.click();

      // Wait for modal to appear and click the submit button inside it
      const modalSubmit = page.locator('#unsignZoneModal button[name="unsign_zone"]');
      await modalSubmit.waitFor({ state: 'visible', timeout: 5000 });
      await modalSubmit.click();
      await page.waitForLoadState('networkidle');
    }

    // Delete the zone
    await page.goto(`/zones/${zoneId}/delete`);
    const deleteButton = page.locator('button[type="submit"], input[type="submit"]');
    if (await deleteButton.count() > 0) {
      await deleteButton.first().click();
      await page.waitForLoadState('networkidle');
    }
  });
});
