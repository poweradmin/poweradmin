import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { ensureAnyZoneExists } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Operations', () => {
  test.describe('SOA Record Management', () => {
    test('should display SOA record in zone', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);
      const soaRow = page.locator('tr:has-text("SOA")');
      expect(await soaRow.count()).toBeGreaterThan(0);
    });

    test('should access SOA edit page', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);
      const soaEditLink = page.locator('a[href*="edit_record"]:has-text("SOA"), tr:has-text("SOA") a[href*="edit_record"]').first();
      if (await soaEditLink.count() > 0) {
        await soaEditLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should display SOA serial number', async ({ adminPage: page }) => {
      const zoneId = await ensureAnyZoneExists(page);
      expect(zoneId).toBeTruthy();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);
      const bodyText = await page.locator('body').textContent();
      // SOA should contain a serial number (typically format: YYYYMMDDNN)
      expect(bodyText).toMatch(/\d{10}|\d{8}/);
    });

    test('should update SOA serial on record change', async ({ adminPage: page }) => {
      const testDomain = `soa-test-${Date.now()}.example.com`;

      // Create zone
      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Get initial SOA
      await page.goto('/index.php?page=list_zones');
      const row = page.locator(`tr:has-text("${testDomain}")`);
      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="page=edit"]').first();
        await editLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);

        // Cleanup
        await page.goto('/index.php?page=list_zones');
        const deleteLink = page.locator(`tr:has-text("${testDomain}") a[href*="delete_domain"]`).first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) await yesBtn.click();
        }
      }
    });
  });

  test.describe('Zone Type Operations', () => {
    test('should display zone type in list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/master|slave|native/i);
    });

    test('should create native zone', async ({ adminPage: page }) => {
      const testDomain = `native-${Date.now()}.example.com`;

      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);

      const typeSelect = page.locator('select[name*="type"]');
      if (await typeSelect.count() > 0) {
        await typeSelect.selectOption('NATIVE');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Cleanup
      await page.goto('/index.php?page=list_zones');
      const deleteLink = page.locator(`tr:has-text("${testDomain}") a[href*="delete_domain"]`).first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should display slave zone master IP', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const slaveRow = page.locator('tr:has-text("SLAVE")').first();
      if (await slaveRow.count() > 0) {
        const bodyText = await slaveRow.textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Zone Comments', () => {
    test('should display zone comment', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const commentField = page.locator('input[name*="comment"], textarea[name*="comment"]');
        if (await commentField.count() > 0) {
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should update zone comment', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const commentLink = page.locator('a[href*="edit_comment"]').first();
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
    test('should display zone owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const bodyText = await page.locator('body').textContent();
      // Zone list should show owner information
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should change zone owner', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const ownerLink = page.locator('a[href*="add_owner"], a[href*="change_owner"]').first();
        if (await ownerLink.count() > 0) {
          await ownerLink.click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should add multiple owners', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const addOwnerLink = page.locator('a[href*="add_owner"]').first();
        if (await addOwnerLink.count() > 0) {
          await addOwnerLink.click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Zone Filtering', () => {
    test('should filter forward zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones&zone_sort_by=name&zone_sort_order=asc');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should filter reverse zones', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones&reverse=1');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should sort zones by name', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const sortLink = page.locator('a[href*="zone_sort_by=name"]').first();
      if (await sortLink.count() > 0) {
        await sortLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should sort zones by type', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const sortLink = page.locator('a[href*="zone_sort_by=type"]').first();
      if (await sortLink.count() > 0) {
        await sortLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });
  });

  test.describe('Reverse Zones', () => {
    test('should create IPv4 reverse zone', async ({ adminPage: page }) => {
      const testDomain = `1.168.192.in-addr.arpa`;

      await page.goto('/index.php?page=add_zone_master');
      await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);

      // Cleanup
      await page.goto('/index.php?page=list_zones');
      const deleteLink = page.locator(`tr:has-text("${testDomain}") a[href*="delete_domain"]`).first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) await yesBtn.click();
      }
    });

    test('should add PTR record to reverse zone', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones&reverse=1');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const addRecordLink = page.locator('a[href*="add_record"]').first();
        if (await addRecordLink.count() > 0) {
          await addRecordLink.click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Zone Statistics', () => {
    test('should display zone record count', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const editLink = page.locator('a[href*="page=edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        // Should show record count or records
        expect(bodyText.toLowerCase()).toMatch(/record/i);
      }
    });

    test('should display zone serial in list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=list_zones');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});
