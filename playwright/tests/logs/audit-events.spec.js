/**
 * Audit Events Tests
 *
 * Tests that verify audit logging for:
 * - Permission template changes (perm_template_change)
 * - Failed access attempts (access_denied)
 * - User agent in login events (user_agent)
 *
 * These tests generate events then verify they appear in user logs.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Audit Events - Generate Events', () => {
  test('should generate access_denied event by viewer accessing admin page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    await page.goto('/users/logs');

    // Viewer doesn't have permission - should see error or redirect
    const bodyText = await page.locator('body').textContent();
    const wasDenied = bodyText.toLowerCase().includes('error') ||
                      bodyText.toLowerCase().includes('denied') ||
                      !page.url().includes('users/logs');
    expect(wasDenied).toBeTruthy();
  });

  test('should generate perm_template_change by editing noperm user template', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Find the noperm user's edit page
    await page.goto('/users');
    const nopermRow = page.locator('tr:has-text("noperm")').first();
    const editLink = nopermRow.locator('a[href*="/edit"]');

    if (await editLink.count() > 0) {
      await editLink.click();

      const templateSelect = page.locator('select[name="perm_templ"]');
      if (await templateSelect.count() > 0) {
        // Get current value
        const currentValue = await templateSelect.inputValue();

        // Change to a different template (toggle between 4 and 5)
        const newValue = currentValue === '4' ? '5' : '4';
        await templateSelect.selectOption(newValue);

        // Submit the form
        const submitBtn = page.locator('button[type="submit"]').first();
        await submitBtn.click();

        // Should redirect to users list on success
        await page.waitForURL(/.*users/);

        // Now change it back to original
        await page.goto('/users');
        const nopermRow2 = page.locator('tr:has-text("noperm")').first();
        const editLink2 = nopermRow2.locator('a[href*="/edit"]');
        await editLink2.click();

        const templateSelect2 = page.locator('select[name="perm_templ"]');
        await templateSelect2.selectOption(currentValue);
        await page.locator('button[type="submit"]').first().click();
        await page.waitForURL(/.*users/);
      }
    }
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

    const rows = page.locator('table tbody tr');
    if (await rows.count() > 0) {
      const bodyText = await page.locator('table').textContent();
      expect(bodyText).toMatch(/access_denied/);
      // Should include the permission that was denied
      expect(bodyText).toMatch(/permission:/);
    }
  });

  test('should show perm_template_change event in user logs', async ({ page }) => {
    await page.goto('/users/logs');

    const eventSelect = page.locator('select[name="event_type"]');

    // Filter by perm_template_change
    await eventSelect.selectOption('perm_template_change');
    await page.locator('button[type="submit"]').first().click();

    await expect(page).toHaveURL(/event_type=perm_template_change/);

    const rows = page.locator('table tbody tr');
    if (await rows.count() > 0) {
      const bodyText = await page.locator('table').textContent();
      expect(bodyText).toMatch(/perm_template_change/);
      // Should include old and new template IDs
      expect(bodyText).toMatch(/old_template:/);
      expect(bodyText).toMatch(/new_template:/);
    }
  });

  test('should show session_expired event type in filter dropdown', async ({ page }) => {
    await page.goto('/users/logs');

    const eventSelect = page.locator('select[name="event_type"]');
    const options = await eventSelect.locator('option').allTextContents();
    expect(options.some(o => o.includes('session_expired'))).toBeTruthy();
  });

  test('should show user_agent in login events', async ({ page }) => {
    await page.goto('/users/logs');

    // Filter by login_success
    const eventSelect = page.locator('select[name="event_type"]');
    await eventSelect.selectOption('login_success');
    await page.locator('button[type="submit"]').first().click();

    const rows = page.locator('table tbody tr');
    if (await rows.count() > 0) {
      // Click details button on first row to see full event
      const detailsBtn = page.locator('table button[data-bs-toggle="modal"]').first();
      await detailsBtn.click();

      const modal = page.locator('#userLogModal');
      await expect(modal).toBeVisible();

      const modalText = await modal.textContent();
      expect(modalText).toMatch(/user_agent:/);
    }
  });

  test('should show client_ip in log entries', async ({ page }) => {
    await page.goto('/users/logs');

    const rows = page.locator('table tbody tr');
    if (await rows.count() > 0) {
      // client_ip should now be visible in the table (not just in details)
      const tableText = await page.locator('table').textContent();
      expect(tableText).toMatch(/client_ip/);
    }
  });
});
