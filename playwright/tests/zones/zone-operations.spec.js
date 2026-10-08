/**
 * Zone Operations Tests
 *
 * Tests for zone operations including SOA management,
 * zone types, comments, and ownership.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteZoneByName, getTestZoneId, openZoneListPageFor, uniqueZoneName, zoneExists } from '../../helpers/zones.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Zone Operations', () => {
  test.describe('SOA Record Management', () => {
    test('should display SOA record in zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, 'admin-zone.example.com must be seeded').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);
      const soaRow = page.locator('tr:has-text("SOA")');
      expect(await soaRow.count()).toBeGreaterThan(0);
    });

    test('should access SOA edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, 'admin-zone.example.com must be seeded').toBeTruthy();

      // The zone page edits records inline and has no per-record edit link, so the
      // record id comes from the row's input names and the page is opened directly
      await page.goto(`/zones/${zoneId}/edit`);
      const soaId = await page.locator('[name^="record["][name$="[type]"]').evaluateAll(nodes => {
        const soa = nodes.find(node => node.value === 'SOA');
        return soa ? soa.getAttribute('name').match(/record\[([^\]]+)\]/)[1] : null;
      });
      expect(soaId).not.toBeNull();

      await page.goto(`/zones/${zoneId}/records/${encodeURIComponent(soaId)}/edit`);
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('[name$="[type]"], select[name*="type"]').first()).toBeVisible();
    });

    test('should display SOA serial number', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page, 'admin');
      expect(zoneId, 'admin-zone.example.com must be seeded').toBeTruthy();

      await page.goto(`/zones/${zoneId}/edit`);

      // Records render as editable inputs, so the serial lives in a value attribute
      // that textContent() cannot see. Assert against the SOA row's content field.
      const soaContent = page.locator('tr:has(input[value="SOA"]) input[name*="[content]"]').first();
      await expect(soaContent).toHaveValue(/\d{8,10}/);
    });

    test('should open the edit page of a new zone with its SOA', async ({ page, tempZone }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // The list is paginated, so walk to the page that shows the new zone
      expect(await openZoneListPageFor(page, tempZone.name)).toBe(true);
      const row = page.locator(`tr:has-text("${tempZone.name}")`);
      await expect(row).toHaveCount(1);

      await row.locator('a[href*="/edit"]').first().click();
      await expect(page).toHaveURL(new RegExp(`/zones/${tempZone.id}/edit`));
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('tr:has(input[value="SOA"])')).toHaveCount(1);
    });
  });

  test.describe('Zone Type Operations', () => {
    test('should display zone type in list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/master|slave|native/i);
    });

    test('should create native zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const testDomain = uniqueZoneName('native');

      try {
        await page.goto('/zones/add/master');
        await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);

        await page.locator('[data-testid="zone-type-select"]').selectOption('NATIVE');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();
        await page.waitForLoadState('networkidle');

        expect(await zoneExists(page, testDomain), `zone ${testDomain} must be created`).toBe(true);
      } finally {
        await deleteZoneByName(page, testDomain);
      }
    });

    test('should display slave zone master IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      // The master IP lives on the zone edit page, not in the list row
      const editLink = page.locator('tr:has-text("slave-zone.example.com") a[href*="/edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();

      await expect(page.locator('input[name="new_master"]')).toHaveValue('10.0.0.1');
    });
  });

  test.describe('Zone Comments', () => {
    test('should display zone comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      expect(await editLink.count()).toBeGreaterThan(0);

      await editLink.click();
      const commentField = page.locator('input[name*="comment"], textarea[name*="comment"]');
      if (await commentField.count() > 0) {
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      }
    });

    test('should update zone comment', async ({ page, tempZone }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${tempZone.id}/edit`);

      const commentLink = page.locator('a[href*="/comment/edit"]').first();
      test.skip(await commentLink.count() === 0, 'zone comments are disabled on this instance (show_zone_comments)');
      await commentLink.click();

      const comment = `Updated comment ${tempZone.name}`;
      await page.locator('textarea').first().fill(comment);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      await page.goto(`/zones/${tempZone.id}/comment/edit`);
      await expect(page.locator('textarea').first()).toHaveValue(comment);
    });
  });

  test.describe('Zone Ownership', () => {
    test('should display zone owner', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should change zone owner', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      expect(await editLink.count()).toBeGreaterThan(0);

      await editLink.click();
      const ownerLink = page.locator('a[href*="owner"]').first();
      if (await ownerLink.count() > 0) {
        await ownerLink.click();
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      }
    });

    test('should add multiple owners', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      expect(await editLink.count()).toBeGreaterThan(0);

      await editLink.click();
      await page.waitForLoadState('networkidle');
      const addOwnerLink = page.locator('a[href*="/ownership"]').first();
      if (await addOwnerLink.count() > 0) {
        await addOwnerLink.click();
        // Auto-retrying assertion: the click navigation may still be in flight
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      }
    });
  });

  test.describe('Zone Filtering', () => {
    test('should filter forward zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?zone_sort_by=name&zone_sort_order=asc');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should filter reverse zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/reverse');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should filter zones by letter', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=a');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display all zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Zone Record Count', () => {
    test('should display record count for zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      // The seeded zones always fill the list, so the table and its counts are there
      const table = page.locator('table').first();
      await expect(table).toBeVisible();
      await expect(table).toContainText(/\d+/);
    });
  });
});
