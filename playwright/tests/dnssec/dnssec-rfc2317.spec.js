/**
 * DNSSEC RFC 2317 Classless Reverse Zone Tests
 *
 * Tests that DNSSEC signing works correctly for RFC 2317 zones
 * whose names contain a forward slash (e.g. 0/26.1.168.192.in-addr.arpa).
 *
 * @see https://github.com/poweradmin/poweradmin/issues/994
 */

import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { createTempZone, deleteZoneById } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNSSEC for RFC 2317 Classless Reverse Zones', () => {
  // A random /26 under 10.x.y keeps reruns and parallel runs from colliding
  const start = [0, 64, 128, 192][Math.floor(Math.random() * 4)];
  const rfc2317Zone = `${start}/26.${Math.floor(Math.random() * 256)}.${Math.floor(Math.random() * 256)}.10.in-addr.arpa`;
  let zoneId = null;
  let signed = false;

  test.afterAll(async ({ browser, baseURL }) => {
    if (!zoneId) {
      return;
    }
    const context = await browser.newContext({ baseURL });
    try {
      const page = await context.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await deleteZoneById(page, zoneId);
    } finally {
      await context.close();
    }
  });

  test('should create an RFC 2317 classless reverse zone', async ({ adminPage: page }) => {
    // createTempZone also adds the apex NS record, without which signing is refused
    ({ id: zoneId } = await createTempZone(page, { name: rfc2317Zone }));
    expect(zoneId, 'the RFC 2317 zone must be created').toBeTruthy();
  });

  test('should sign RFC 2317 zone without API error', async ({ adminPage: page }) => {
    expect(zoneId, 'the creation test must have produced the zone').toBeTruthy();

    await page.goto(`/zones/${zoneId}/edit`);

    // The button renders only when DNSSEC is enabled in the configuration
    const signButton = page.locator('button[name="sign_zone"]');
    test.skip(await signButton.count() === 0, 'Sign zone button not available - DNSSEC is not enabled on this instance');

    await signButton.click();
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Should NOT show the API error from issue #994
    expect(bodyText).not.toContain('PowerDNS API returned an error');
    expect(bodyText).not.toContain('Failed to sign zone');

    // A signed zone swaps the Sign zone button for the Manage DNSSEC link
    await page.goto(`/zones/${zoneId}/edit`);
    await expect(page.locator(`a[href$="/zones/${zoneId}/dnssec"]`).first()).toBeVisible();
    signed = true;
  });

  test('should access DNSSEC management page for signed RFC 2317 zone', async ({ adminPage: page }) => {
    expect(zoneId, 'the creation test must have produced the zone').toBeTruthy();

    await page.goto(`/zones/${zoneId}/dnssec`);

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(bodyText.toLowerCase()).toMatch(/dnssec|key|secure/i);
  });

  test('should unsign and delete RFC 2317 zone', async ({ adminPage: page }) => {
    expect(zoneId, 'the creation test must have produced the zone').toBeTruthy();

    if (signed) {
      await page.goto(`/zones/${zoneId}/dnssec`);

      // The "Unsign zone" toolbar button opens a Bootstrap modal
      await page.locator('button[data-bs-target="#unsignZoneModal"]').click();
      const modalSubmit = page.locator('#unsignZoneModal button[name="unsign_zone"]');
      await modalSubmit.waitFor({ state: 'visible', timeout: 5000 });
      await modalSubmit.click();
      await page.waitForLoadState('networkidle');
    }

    expect(await deleteZoneById(page, zoneId), 'the RFC 2317 zone must be deletable').toBe(true);
  });
});
