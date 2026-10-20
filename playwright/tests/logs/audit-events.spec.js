/**
 * Audit Events Tests
 *
 * Tests that verify audit logging for:
 * - Permission template changes (perm_template_change)
 * - Failed access attempts (access_denied)
 * - User agent in login events (browser)
 *
 * These tests generate events then verify they appear in user logs.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { expectAccessDenied } from '../../helpers/access.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Audit Events - Generate Events', () => {
  test('should generate access_denied event by viewer accessing admin page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    await page.goto('/users/logs');

    // The denial is what writes the access_denied audit event
    await expectAccessDenied(page, 'table');
  });

  test('should generate perm_template_change by editing noperm user template', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // The list renders the username as an input value, so tr:has-text never matches it
    const nopermRow = `tr:has(input[value="${users.noperm.username}"])`;
    await page.goto(`/users?search=${users.noperm.username}`);
    await expect(page.locator(nopermRow)).toHaveCount(1);
    await page.locator(nopermRow).locator('a[href*="/edit"]').first().click();

    const templateSelect = page.locator('select[name="perm_templ"]');
    await expect(templateSelect).toBeVisible();
    const currentValue = await templateSelect.inputValue();

    // Change to a different template (toggle between 4 and 5)
    const newValue = currentValue === '4' ? '5' : '4';
    await templateSelect.selectOption(newValue);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/.*users/);

    // Put the template back so the fixture user keeps the rights other specs expect
    await page.goto(`/users?search=${users.noperm.username}`);
    await page.locator(nopermRow).locator('a[href*="/edit"]').first().click();
    await page.locator('select[name="perm_templ"]').selectOption(currentValue);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL(/.*users/);
  });
});

test.describe('Audit Events - Verify in User Logs', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should show access_denied event in user logs', async ({ page }) => {
    await page.goto('/users/logs');

    const eventSelect = page.locator('select[name="event_type"]');
    const options = await eventSelect.locator('option').allTextContents();

    // Verify access_denied is in the event type dropdown
    expect(options.some(o => o.includes('access_denied'))).toBeTruthy();

    // Filter by access_denied
    await eventSelect.selectOption('access_denied');
    await page.locator('button[type="submit"]').first().click();

    await expect(page).toHaveURL(/event_type=access_denied/);

    // The first describe denied the viewer, so a row is guaranteed here
    await expect(page.locator('table tbody tr').first()).toBeVisible();
    await expect(page.locator('table')).toContainText(/access_denied/);
    // Should include the permission that was denied
    await expect(page.locator('table')).toContainText(/permission:/);
  });

  test('should show perm_template_change event in user logs', async ({ page }) => {
    await page.goto('/users/logs');

    const eventSelect = page.locator('select[name="event_type"]');

    // Filter by perm_template_change
    await eventSelect.selectOption('perm_template_change');
    await page.locator('button[type="submit"]').first().click();

    await expect(page).toHaveURL(/event_type=perm_template_change/);

    // The first describe changed the noperm user's template, so a row is guaranteed here
    await expect(page.locator('table tbody tr').first()).toBeVisible();
    await expect(page.locator('table')).toContainText(/perm_template_change/);
    // Should include old and new template IDs
    await expect(page.locator('table')).toContainText(/old_template:/);
    await expect(page.locator('table')).toContainText(/new_template:/);
  });

  test('should show session_expired event type in filter dropdown', async ({ page }) => {
    await page.goto('/users/logs');

    const eventSelect = page.locator('select[name="event_type"]');
    const options = await eventSelect.locator('option').allTextContents();
    expect(options.some(o => o.includes('session_expired'))).toBeTruthy();
  });

  test('should show auth_method in login events', async ({ page }) => {
    await page.goto('/users/logs');

    // Filter by login_success
    const eventSelect = page.locator('select[name="event_type"]');
    await eventSelect.selectOption('login_success');
    await page.locator('button[type="submit"]').first().click();

    // The seeded login attempts plus this suite's own logins guarantee login_success rows
    await expect(page.locator('table tbody tr').first()).toBeVisible();

    await page.locator('table button[data-bs-toggle="modal"]').first().click();

    const modal = page.locator('#userLogModal');
    await expect(modal).toBeVisible();
    await expect(modal).toContainText(/auth_method:/);
  });

  test('should show client_ip in log entries', async ({ page }) => {
    await page.goto('/users/logs');

    await expect(page.locator('table tbody tr').first()).toBeVisible();

    // client_ip is shown in the table itself, not only in the details modal
    await expect(page.locator('table')).toContainText(/client_ip/);
  });
});
