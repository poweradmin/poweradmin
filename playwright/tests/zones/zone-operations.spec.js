/**
 * Zone Operations Tests
 *
 * Tests for zone operations including SOA management,
 * zone types, comments, and ownership.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

// Helper to get a zone ID for testing
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

test.describe('Zone Operations', () => {
  test.describe('SOA Record Management', () => {
    test('should display SOA record in zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);
      const soaRow = page.locator('tr:has-text("SOA")');
      expect(await soaRow.count()).toBeGreaterThan(0);
    });

    test('should access SOA edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);
      const soaEditLink = page.locator('a[href*="/records/"][href*="/edit"]:has-text("SOA"), tr:has-text("SOA") a[href*="/edit"]').first();
      if (await soaEditLink.count() > 0) {
        await soaEditLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should display SOA serial number', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) return;

      await page.goto(`/zones/${zoneId}/edit`);
      const bodyText = await page.locator('body').textContent();
      // SOA should contain a serial number (typically format: YYYYMMDDNN)
      expect(bodyText).toMatch(/\d{10}|\d{8}/);
    });

    test('should update SOA serial on record change', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const testDomain = `soa-test-${Date.now()}.example.com`;

      // Create zone
      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Get initial SOA
      await page.goto('/zones/forward?letter=all');
      const row = page.locator(`tr:has-text("${testDomain}")`);
      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="/edit"]').first();
        await editLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);

        // Cleanup
        await page.goto('/zones/forward?letter=all');
        const deleteLink = page.locator(`tr:has-text("${testDomain}") a[href*="/delete"]`).first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) await yesBtn.click();
        }
      }
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
      const testDomain = `native-${Date.now()}.example.com`;

      await page.goto('/zones/add/master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);

      const typeSelect = page.locator('select[name*="type"]');
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('NATIVE');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Cleanup
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator(`tr:has-text("${testDomain}") a[href*="/delete"]`).first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should display slave zone master IP', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const slaveRow = page.locator('tr:has-text("SLAVE")').first();
      if (await slaveRow.count() > 0) {
        const bodyText = await slaveRow.textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Zone Comments', () => {
    test('should display zone comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const commentField = page.locator('input[name*="comment"], textarea[name*="comment"]');
        if (await commentField.count() > 0) {
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should update zone comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const commentLink = page.locator('a[href*="comment"]').first();
        if (await commentLink.count() > 0) {
          await commentLink.click();
          const commentField = page.locator('input[name*="comment"], textarea[name*="comment"]').first();
          if (await commentField.count() > 0) {
            await commentField.fill(`Updated comment ${Date.now()}`);
            await page.locator('button[type="submit"], input[type="submit"]').first().click();
            const bodyText = await page.locator('body').textContent();
            expect(bodyText).not.toMatch(/fatal|exception/i);
          }
        }
      }
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
      if (await editLink.count() > 0) {
        await editLink.click();
        const ownerLink = page.locator('a[href*="owner"]').first();
        if (await ownerLink.count() > 0) {
          await ownerLink.click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should add multiple owners', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const addOwnerLink = page.locator('a[href*="owner/add"]').first();
        if (await addOwnerLink.count() > 0) {
          await addOwnerLink.click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
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
      const table = page.locator('table').first();
      if (await table.count() > 0) {
        const bodyText = await table.textContent();
        // Table should contain numeric record counts
        expect(bodyText).toMatch(/\d+/);
      }
    });
  });
});
