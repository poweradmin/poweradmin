/**
 * Add Record row limit
 *
 * Each row of the multi-record form posts up to 8 fields, so past about 125 rows
 * PHP's max_input_vars (1000) silently drops the rest. The form caps the rows,
 * and a submit missing its trailing form_complete field adds nothing.
 */

import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

const zone = useFileZone('rowlimit');

test.describe('Add Record row limit', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto(`/zones/${zone.id}/records/add`);
    await page.waitForLoadState('networkidle');
  });

  test('stops adding rows at the limit and allows more again after a removal', async ({ page }) => {
    const rows = page.locator('#recordsTableBody .record-row');
    const addButton = page.locator('#addRecordButton');

    await page.evaluate(() => {
      for (let i = 0; i < 150; i++) {
        window.addNewRecord();
      }
    });

    await expect(rows).toHaveCount(100);
    await expect(addButton).toBeDisabled();
    await expect(page.locator('#rowLimitNotice')).toBeVisible();
    await expect(page.locator('#rowLimitNotice')).toContainText('100');

    await rows.last().locator('.remove-record').click();
    await expect(rows).toHaveCount(99);
    await expect(addButton).toBeEnabled();
    await expect(page.locator('#rowLimitNotice')).toBeHidden();
  });

  test('the completion marker is the last field of the form', async ({ page }) => {
    const last = await page.locator('#recordsTable').evaluate((table) => {
      const elements = table.closest('form').elements;
      return elements[elements.length - 1].name;
    });

    expect(last).toBe('form_complete');
  });

  test('a submit without the completion marker adds nothing', async ({ page }) => {
    const name = `cut-${Date.now()}`;
    await page.locator('input[name="records[0][name]"]').fill(name);
    await page.locator('select[name="records[0][type]"]').selectOption('A');
    await page.locator('input[name="records[0][content]"]').fill('192.0.2.10');
    await page.locator('input[name="form_complete"]').evaluate((input) => input.remove());

    await page.locator('button[name="commit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/\/records\/add\?form_id=/);
    await expect(page.locator('.alert-danger')).toContainText('more fields than the server accepts');
    await expect(page.locator('input[name="records[0][name]"]')).toHaveValue(name);

    await page.goto(`/zones/${zone.id}/edit`);
    await expect(page.locator('body')).not.toContainText(name);
  });
});
