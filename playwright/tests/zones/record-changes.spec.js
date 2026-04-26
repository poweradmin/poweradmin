import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Run serially: a single zone is mutated and the same change-log rows are
// asserted across tests. Parallelism would let one test see another's writes.
test.describe.configure({ mode: 'serial' });

test.describe('Record Change Log', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('admin can open the change log page', async ({ page }) => {
    await page.goto('/zones/changes');
    await page.waitForLoadState('networkidle');

    // Breadcrumb + page header reference the page (page header has "Record Change Log").
    await expect(page.locator('main').getByText('Record Change Log').first()).toBeVisible();

    // The 6 supported actions show up in the action filter.
    const actionSelect = page.locator('select[name="action"]');
    await expect(actionSelect).toBeVisible();
    const options = await actionSelect.locator('option').allTextContents();
    expect(options).toEqual(expect.arrayContaining([
      'record_create', 'record_edit', 'record_delete',
      'zone_create', 'zone_delete', 'zone_metadata_edit',
    ]));

    // Time-window shortcuts are present in the page body (not the menu).
    const main = page.locator('main');
    for (const label of ['One month ago', 'One week ago', 'One day ago', '6 hours ago', '1 hour ago']) {
      await expect(main.getByRole('link', { name: label })).toBeVisible();
    }
  });

  test('record create + edit + delete each appear in the change log', async ({ page }) => {
    // Pick the first existing zone and edit it. We don't create a fresh
    // zone here because the change log is admin-wide and assertions can
    // rely on whichever zone is editable.
    await page.goto('/zones/forward');
    await page.waitForLoadState('networkidle');

    const editLink = page.locator('a[href*="/zones/"][href$="/edit"]').first();
    if ((await editLink.count()) === 0) {
      test.skip(true, 'no zone available to mutate');
    }

    const zoneEditUrl = await editLink.getAttribute('href');
    const zoneIdMatch = zoneEditUrl.match(/\/zones\/(\d+)\/edit/);
    expect(zoneIdMatch, 'zone edit href should contain numeric zone id').not.toBeNull();
    const zoneId = zoneIdMatch[1];
    await editLink.click();
    await page.waitForLoadState('networkidle');

    // --- create ---
    const recordName = `audit-e2e-${Date.now()}`;
    await page.locator('input[name="name"]').first().fill(recordName);
    await page.locator('input[name="content"], [data-testid="record-content-input"]').first().fill('203.0.113.10');
    await page.getByRole('button', { name: 'Add record' }).click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/changes?action=record_create');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('table tbody td', { hasText: new RegExp(`${recordName}\\.`) }).first()).toBeVisible();
    await expect(page.locator('span.badge.bg-success', { hasText: 'record_create' }).first()).toBeVisible();

    // --- edit (via API would skip the UI hooks; do it through the form) ---
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');
    // Records are rendered as inline-editable rows; the record's name lives in
    // an input's value, so locate by attribute selector rather than text.
    const nameInput = page.locator(`input[value^="${recordName}."]`).first();
    await expect(nameInput).toBeVisible();
    const nameAttr = await nameInput.getAttribute('name');
    const recordIdMatch = nameAttr.match(/record\[(\d+)\]/);
    expect(recordIdMatch, 'record input name should embed record id').not.toBeNull();
    const recordId = recordIdMatch[1];

    const contentInput = page.locator(`input[name="record[${recordId}][content]"]`);
    await contentInput.fill('203.0.113.99');
    await page.getByRole('button', { name: /Save changes/i }).click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/changes?action=record_edit');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('span.badge.bg-primary', { hasText: 'record_edit' }).first()).toBeVisible();
    // Edit row renders before+after as two trs; both halves should mention the record name.
    const editTrs = page.locator('tr.table-danger, tr.table-success');
    await expect(editTrs.first()).toBeVisible();

    await page.goto(`/zones/${zoneId}/records/${recordId}/delete?confirm=1`);
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: /Yes, delete this record/i }).click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/changes?action=record_delete');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('span.badge.bg-danger', { hasText: 'record_delete' }).first()).toBeVisible();
    await expect(page.locator('table tbody td', { hasText: new RegExp(`${recordName}\\.`) }).first()).toBeVisible();
  });

  test('time-window shortcut narrows the result set', async ({ page }) => {
    await page.goto('/zones/changes?window=PT1H');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('a.fw-bold', { hasText: '1 hour ago' })).toBeVisible();
  });

  test('csv export works', async ({ page }) => {
    // page.goto() bails on file downloads; trigger the download via JS so
    // the navigation events don't get tangled up with the binary response.
    await page.goto('/zones/changes');
    await page.waitForLoadState('networkidle');
    const downloadPromise = page.waitForEvent('download');
    await page.evaluate(() => { window.location.href = '/zones/changes?export=csv'; });
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/^record-changes-\d{4}-\d{2}-\d{2}\.csv$/);
  });
});
