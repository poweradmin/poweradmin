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

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Zone Template Defaults (issue #973)', () => {
  const templateName = `default-flag-${Date.now()}`;
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
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const m = href && href.match(/\/templates\/(\d+)/);
      if (m) templateId = m[1];
    }
    await ctx.close();
  });

  test.afterAll(async ({ browser }) => {
    if (!templateId) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto(`/zones/templates/${templateId}/delete`).catch(() => {});
    await page.waitForLoadState('networkidle');
    const confirm = page.locator('a[href*="confirm"], button:has-text("Yes"), input[value="Yes"]').first();
    if (await confirm.count() > 0) {
      await confirm.click().catch(() => {});
      await page.waitForLoadState('networkidle');
    }
    await ctx.close();
  });

  test('admin sets a global template as default and sees the badge', async ({ page }) => {
    test.skip(!templateId, 'template setup did not produce an id');

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
    test.skip(!templateId, 'template setup did not produce an id');

    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const select = page.locator('[data-testid="zone-template-select"], select[name*="template"]').first();
    await expect(select).toBeVisible();

    await expect(select).toHaveValue(String(templateId));

    const selectedText = await select.locator(`option[value="${templateId}"]`).textContent();
    expect(selectedText).toMatch(/default/i);
  });

  test('admin unsets the default and the badge disappears', async ({ page }) => {
    test.skip(!templateId, 'template setup did not produce an id');

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
    test.skip(!templateId, 'template setup did not produce an id');

    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const setForms = page.locator('form[action*="set-default"]');
    await expect(setForms).toHaveCount(0);
  });
});
