import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneById, deleteZoneByName, findZoneIdByName, uniqueZoneName, zoneExists } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Run tests serially as they depend on each other
test.describe.configure({ mode: 'serial' });

test.describe('Master Zone Management', () => {
  const masterZone = uniqueZoneName('addmaster');
  // Random octets keep the reverse zone clear of the seeded and other specs' networks
  const reverseZone = `${Math.floor(Math.random() * 256)}.${Math.floor(Math.random() * 256)}.10.in-addr.arpa`;

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await deleteZoneByName(page, masterZone);
      await deleteZoneByName(page, reverseZone);
    } finally {
      await page.close();
    }
  });

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should add a master zone successfully', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(masterZone);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

    // The zone was just created above, so it must resolve by name
    expect(await findZoneIdByName(page, masterZone), `zone ${masterZone} must be created`).toBeTruthy();
  });

  test('should add a reverse zone successfully', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(reverseZone);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify no errors occurred
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

    expect(await findZoneIdByName(page, reverseZone), `zone ${reverseZone} must be created`).toBeTruthy();
  });

  test('should add a record to a master zone successfully', async ({ page }) => {
    const zoneId = await findZoneIdByName(page, masterZone);
    expect(zoneId, `zone ${masterZone} must exist from the first test`).toBeTruthy();

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');

    // The add record form names its inputs plain name/content, inline rows use record[<id>][...]
    await page.locator('input[name="name"]').first().fill('www');
    await page.locator('input[name="content"], [data-testid="record-content-input"]').first().fill('192.168.1.1');
    await page.getByRole('button', { name: 'Add record' }).click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

    await page.goto(`/zones/${zoneId}/edit`);
    await expect(page.locator('input[value="192.168.1.1"]')).toHaveCount(1);
  });

  test('should delete a master zone successfully', async ({ page }) => {
    const zoneId = await findZoneIdByName(page, masterZone);
    expect(zoneId, `zone ${masterZone} must exist from the first test`).toBeTruthy();

    expect(await deleteZoneById(page, zoneId), 'delete confirmation must be offered').toBe(true);

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    expect(await zoneExists(page, masterZone)).toBe(false);
  });

  test('should delete a reverse zone successfully', async ({ page }) => {
    const zoneId = await findZoneIdByName(page, reverseZone);
    expect(zoneId, `zone ${reverseZone} must exist from the second test`).toBeTruthy();

    expect(await deleteZoneById(page, zoneId), 'delete confirmation must be offered').toBe(true);

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    expect(await zoneExists(page, reverseZone)).toBe(false);
  });
});
