import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { findZoneIdByName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('SOA Serial Increment - Issue #1122', () => {
  /**
   * Bug: Adding a record via the inline form on the zone edit page
   * increments the SOA serial by 2 instead of 1.
   *
   * Root cause: updateSOASerial() is called twice - once in
   * RecordManager.addRecordGetId() and again in EditController.addRecord().
   */

  // Exact-serial assertion needs a zone no parallel worker writes to, so the
  // test creates its own instead of borrowing a shared fixture zone.
  async function createIsolatedZone(page, soaEditApi = null) {
    const zoneName = `soa-serial-${Date.now()}-${Math.random().toString(36).slice(2, 6)}.example.com`;
    await page.goto('/zones/add/master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(zoneName);
    if (soaEditApi !== null) {
      await page.locator('[data-testid="soa-edit-api-select"]').selectOption(soaEditApi);
    }
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // The zone list is paginated, so resolve the id by name (with the search
    // fallback) instead of expecting the new zone on the first page.
    return await findZoneIdByName(page, zoneName);
  }

  test('inline add record should increment SOA serial by exactly 1', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await createIsolatedZone(page);
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
    await addForm.locator('[name="commit"]').click();

    await page.waitForLoadState('domcontentloaded');

    // Verify the record was added successfully
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain('successfully added');

    // Get the SOA serial after adding the record
    const serialAfter = parseInt(await page.locator('input[name="serial"]').inputValue(), 10);

    // The serial should increment by exactly 1, not 2
    expect(serialAfter - serialBefore).toBe(1);
  });

  // Issue #1556: with SOA-EDIT-API set, PowerDNS bumps the serial on every PATCH,
  // so the record write and the comment must go to the API as one request.
  test('editing a record comment on the zone edit page should increment SOA serial by exactly 1', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await createIsolatedZone(page, 'DEFAULT');
    expect(zoneId).not.toBeNull();

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('domcontentloaded');

    const addForm = page.locator('form[action*="/edit"]').first();
    await addForm.locator('input[name="name"]').fill('commented');
    await addForm.locator('select[name="type"]').selectOption('A');
    await addForm.locator('input[name="content"]').fill('192.0.2.11');
    await addForm.locator('[name="commit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText('successfully added');

    const serialBefore = parseInt(await page.locator('input[name="serial"]').inputValue(), 10);
    expect(serialBefore).toBeGreaterThan(0);

    // Only the comment changes; the record data stays as it is
    const commentField = page.locator('input[name^="record["][name$="[comment]"]').first();
    await expect(commentField).toBeVisible();
    await commentField.fill('why this record exists');
    await commentField.locator('xpath=ancestor::form[1]').locator('[name="commit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText('updated successfully');

    const serialAfter = parseInt(await page.locator('input[name="serial"]').inputValue(), 10);
    expect(serialAfter - serialBefore).toBe(1);
    await expect(commentField).toHaveValue('why this record exists');
  });

  test('inline add record with a comment should increment SOA serial by exactly 1', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await createIsolatedZone(page, 'DEFAULT');
    expect(zoneId).not.toBeNull();

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('domcontentloaded');

    const serialBefore = parseInt(await page.locator('input[name="serial"]').inputValue(), 10);
    expect(serialBefore).toBeGreaterThan(0);

    const addForm = page.locator('form[action*="/edit"]').first();
    await addForm.locator('input[name="name"]').fill('with-comment');
    await addForm.locator('select[name="type"]').selectOption('A');
    await addForm.locator('input[name="content"]').fill('192.0.2.12');
    await addForm.locator('input[name="comment"]').fill('added together with the record');
    await addForm.locator('[name="commit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText('successfully added');

    const serialAfter = parseInt(await page.locator('input[name="serial"]').inputValue(), 10);
    expect(serialAfter - serialBefore).toBe(1);
    const newRow = page.locator('tr', { has: page.locator('input[name$="[content]"][value="192.0.2.12"]') });
    await expect(newRow.locator('input[name$="[comment]"]')).toHaveValue('added together with the record');
  });
});
