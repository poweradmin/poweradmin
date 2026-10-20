import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { findZoneIdByName, deleteZoneById } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

/*
 * Regression: issue #1323 - creating a "reverse lookup zone" from the reverse
 * add form silently created a forward zone. The form now converts a network in
 * CIDR notation into the matching in-addr.arpa / ip6.arpa zone, and rejects
 * input that is neither a network nor a reverse zone name.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Reverse zone creation from network input (#1323)', () => {
  // Octet derived from the clock to avoid colliding with existing zones.
  const octet = (Date.now() % 200) + 20;
  const ipv4Network = `10.250.${octet}.0/24`;
  const ipv4ReverseZone = `${octet}.250.10.in-addr.arpa`;
  const ipv6Network = '2001:db8:beef::/48';
  const ipv6Display = '2001:db8:beef';
  // The zone the network expands to. Cleanup resolves zones by name, and the
  // display string above is not one, so the zone would otherwise be left behind
  // and the next run would fail because it already exists.
  const ipv6ReverseZone = 'f.e.e.b.8.b.d.0.1.0.0.2.ip6.arpa';
  const createdZones = [];

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    // Resolve by name rather than reading page one of the list: the reverse list
    // paginates too, and forcing the page size writes a stored user preference
    for (const zone of createdZones) {
      const zoneId = await findZoneIdByName(page, zone);
      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }
    }
    await page.close();
  });

  test('IPv4 network creates the matching in-addr.arpa reverse zone', async ({ page }) => {
    createdZones.push(ipv4ReverseZone);

    await page.goto('/zones/add/master?type=reverse');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(ipv4Network);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // The network must be classified as reverse, so we land on the reverse list.
    await expect(page).toHaveURL(/\/zones\/reverse/);
    await expect(page.locator(`tr:has-text("${ipv4ReverseZone}")`)).toBeVisible();

    // It must not have leaked into the forward zone list.
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(ipv4Network);
  });

  test('IPv6 network creates the matching ip6.arpa reverse zone', async ({ page }) => {
    createdZones.push(ipv6ReverseZone);

    await page.goto('/zones/add/master?type=reverse');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(ipv6Network);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/\/zones\/reverse/);
    await expect(page.locator('body')).toContainText(ipv6Display);
  });

  test('non-network, non-reverse input is rejected instead of creating a forward zone', async ({ page }) => {
    const badName = 'not-a-reverse-zone';

    await page.goto('/zones/add/master?type=reverse');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(badName);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Stays on the add form with an error, does not redirect to a zone list.
    await expect(page).toHaveURL(/\/zones\/add\/master/);
    await expect(page.locator('.alert-danger, .alert.alert-danger')).toContainText(/network|reverse zone/i);

    // And no forward zone with that literal name was created.
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(badName);
  });
});
