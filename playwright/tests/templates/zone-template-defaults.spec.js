/**
 * Zone Template Defaults Tests (issue #973)
 *
 * Covers:
 * - Setting a global template as default via the list-page button
 * - "(default)" badge appearing on the marked row
 * - Pre-selection and "(default)" suffix in the add-zone template dropdown
 * - Unsetting clears the badge and the dropdown reverts to "none"
 *
 * Only ueberusers see the set/unset button, and only global templates
 * (`owner = 0`) can carry the flag.
 */

import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { deleteTemplate } from '../../helpers/templates.js';
import { uniqueName } from '../../helpers/zones.js';

test.describe.configure({ mode: 'serial' });

test.describe('Zone Template Defaults (issue #973)', () => {
  const templateName = uniqueName('zdef');
  let templateId = null;

  test.beforeAll(async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/zones/templates/add');
    await page.waitForLoadState('networkidle');

    await page.locator('input[name="templ_name"]').fill(templateName);
    await page.locator('input[name="templ_global"]').check();

    await page.locator('button[name="commit"]').click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');
    const row = page.locator(`tr:has-text("${templateName}")`).first();
    const editLink = row.locator('a[href*="/edit"]').first();
    await expect(editLink).toBeVisible();
    const href = await editLink.getAttribute('href');
    const m = href && href.match(/\/templates\/(\d+)/);
    expect(m, `no template id in ${href}`).not.toBeNull();
    templateId = m[1];
    await ctx.close();
  });

  test.afterAll(async ({ browser }) => {
    if (!templateId) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // A failed run can leave the system-wide default on this template
      await page.goto('/zones/templates');
      const unsetBtn = page.locator('.template-row').filter({ hasText: templateName })
        .locator('form[action*="set-default"] button[title*="Unset"]');
      if (await unsetBtn.count() > 0) {
        await unsetBtn.click();
        await page.waitForLoadState('networkidle');
      }
      await deleteTemplate(page, templateId);
    } finally {
      await ctx.close();
    }
  });

  test('admin sets a global template as default and sees the badge', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const row = page.locator(`tr:has-text("${templateName}")`).first();
    await expect(row).toBeVisible();

    const setForm = row.locator('form[action*="set-default"]');
    await expect(setForm).toBeVisible();

    const setBtn = setForm.locator('button[title*="Set as default"]');
    await expect(setBtn).toBeVisible();
    await setBtn.click();
    await page.waitForLoadState('networkidle');

    const reloadedRow = page.locator(`tr:has-text("${templateName}")`).first();
    await expect(reloadedRow.locator('.badge:has-text("default")')).toBeVisible();

    const unsetBtn = reloadedRow.locator('form[action*="set-default"] button[title*="Unset"]');
    await expect(unsetBtn).toBeVisible();
  });

  test('add-zone form pre-selects the default template and labels the option', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // The default flag is a single system-wide value, so claim it here instead
    // of relying on what the previous test left behind.
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');
    const row = page.locator(`tr:has-text("${templateName}")`).first();
    const setBtn = row.locator('form[action*="set-default"] button[title*="Set as default"]');
    if (await setBtn.count() > 0) {
      await setBtn.click();
      await page.waitForLoadState('networkidle');
    }

    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const select = page.locator('[data-testid="zone-template-select"], select[name*="template"]').first();
    await expect(select).toBeVisible();

    await expect(select).toHaveValue(String(templateId));

    const selectedText = await select.locator(`option[value="${templateId}"]`).textContent();
    expect(selectedText).toMatch(/default/i);
  });

  test('admin unsets the default and the badge disappears', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const row = page.locator(`tr:has-text("${templateName}")`).first();
    const unsetBtn = row.locator('form[action*="set-default"] button[title*="Unset"]');
    await expect(unsetBtn).toBeVisible();
    await unsetBtn.click();
    await page.waitForLoadState('networkidle');

    const reloadedRow = page.locator(`tr:has-text("${templateName}")`).first();
    await expect(reloadedRow.locator('.badge:has-text("default")')).toHaveCount(0);

    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');
    const select = page.locator('[data-testid="zone-template-select"], select[name*="template"]').first();
    await expect(select).toHaveValue('none');
  });

  test('non-admin users do not see set-default controls', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const setForms = page.locator('form[action*="set-default"]');
    await expect(setForms).toHaveCount(0);
  });
});
