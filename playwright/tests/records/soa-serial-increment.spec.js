import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('SOA Serial Increment - Issue #1122', () => {
  /**
   * Bug: Adding a record via the inline form on the zone edit page
   * increments the SOA serial by 2 instead of 1.
   *
   * Root cause: updateSOASerial() is called twice - once in
   * RecordManager.addRecordGetId() and again in EditController.addRecord().
   */

  async function getTestZoneId(page) {
    await page.goto('/zones/forward?letter=all');
    const editLink = page.locator('table a[href*="/zones/"][href*="/edit"]').first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)\/edit/);
      return match ? match[1] : null;
    }
    return null;
  }

  test('inline add record should increment SOA serial by exactly 1', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    expect(zoneId).not.toBeNull();

    // Navigate to zone edit page
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('domcontentloaded');

    // Get the current SOA serial from the hidden input
    const serialBefore = parseInt(await page.locator('input[name="serial"]').inputValue(), 10);
    expect(serialBefore).toBeGreaterThan(0);

    // Add a record via the inline form
    const uniqueName = `soa-test-${Date.now()}`;
    const addForm = page.locator('form[action*="/edit"]').first();
    await addForm.locator('input[name="name"]').fill(uniqueName);
    await addForm.locator('select[name="type"]').selectOption('A');
    await addForm.locator('input[name="content"]').fill('192.0.2.10');
    await addForm.locator('input[name="commit"]').click();

    await page.waitForLoadState('domcontentloaded');

    // Verify the record was added successfully
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain('successfully added');

    // Get the SOA serial after adding the record
    const serialAfter = parseInt(await page.locator('input[name="serial"]').inputValue(), 10);

    // The serial should increment by exactly 1, not 2
    expect(serialAfter - serialBefore).toBe(1);
  });
});
