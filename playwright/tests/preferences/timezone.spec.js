/**
 * User Timezone Preference Tests
 *
 * Tests for the per-user timezone preference (closes #718, completes #717).
 * The selector is a cascading Region -> City pair; the saved value is the
 * full IANA identifier (e.g. "Europe/Berlin") or empty to inherit the global
 * timezone.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard, logout } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('User Timezone Preference', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/user/preferences');
    // Reset to inherit-global so each test starts from a known state.
    await page.locator('#timezone_region').selectOption('');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/user\/preferences/);
  });

  test('renders cascading region and city selects', async ({ page }) => {
    await expect(page.locator('#timezone_region')).toBeVisible();
    await expect(page.locator('#timezone')).toBeVisible();
    await expect(page.locator('#timezone_region option[value=""]')).toHaveText(/Inherit/);
  });

  test('saves selected timezone and persists across reload', async ({ page }) => {
    await page.locator('#timezone_region').selectOption('Europe');
    await page.locator('#timezone').selectOption('Europe/Berlin');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/user\/preferences/);

    // The city select should keep Europe/Berlin selected after reload.
    await expect(page.locator('#timezone')).toHaveValue('Europe/Berlin');
    await expect(page.locator('#timezone_region')).toHaveValue('Europe');
  });

  test('inherit-global clears the saved timezone', async ({ page }) => {
    // Set something first.
    await page.locator('#timezone_region').selectOption('America');
    await page.locator('#timezone').selectOption('America/New_York');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/user\/preferences/);
    await expect(page.locator('#timezone')).toHaveValue('America/New_York');

    // Switch back to Inherit and save.
    await page.locator('#timezone_region').selectOption('');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/user\/preferences/);

    await expect(page.locator('#timezone_region')).toHaveValue('');
    await expect(page.locator('#timezone')).toHaveValue('');
  });

  test.afterEach(async ({ page }) => {
    await logout(page);
  });
});
