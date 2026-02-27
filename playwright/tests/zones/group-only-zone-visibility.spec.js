/**
 * Group-Only Zone Visibility Tests (Issue #1042)
 *
 * Tests that zones with only group ownership (no direct user owner)
 * are visible to group members with appropriate permissions.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard, logout } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Group-Only Zone Visibility (Issue #1042)', () => {
  // group-only-zone.example.com is assigned to Zone Managers group with no direct user owner.
  // The manager user is a member of Zone Managers and has zone_content_view_own permission.
  const groupOnlyZone = 'group-only-zone.example.com';

  test('group member should see group-only zone in forward zones list', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/forward?letter=all');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain(groupOnlyZone);
  });

  test('group member should be able to access group-only zone edit page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/forward?letter=all');

    // Find the row containing the group-only zone and click its edit link
    const zoneRow = page.locator(`tr:has-text("${groupOnlyZone}")`);
    await expect(zoneRow).toBeVisible();
    const editLink = zoneRow.locator('a[href*="/edit"]').first();
    await expect(editLink).toBeVisible();
    await editLink.click();

    await expect(page).toHaveURL(/.*zones\/\d+\/edit/);
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/denied|permission/i);
  });

  test('non-member should not see group-only zone', async ({ page }) => {
    // viewer is in Viewers group, not Zone Managers
    await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
    await page.goto('/zones/forward?letter=all');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toContain(groupOnlyZone);
  });

  test('admin should create zone with group-only ownership', async ({ page }) => {
    const uniqueZone = `group-test-${Date.now()}.example.com`;

    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/add/master');

    // Fill zone name
    await page.locator('[data-testid="zone-name-input"]').fill(uniqueZone);

    // Select "No user owner" radio button
    await page.locator('#owner_none').check();

    // Select Zone Managers group
    const zoneManagersCheckbox = page.locator('.group-item').filter({ hasText: 'Zone Managers' }).locator('.group-checkbox');
    await zoneManagersCheckbox.check();

    // Submit form
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    // Verify zone was created (redirects to zone list or shows success)
    const bodyText = await page.locator('body').textContent();
    const url = page.url();
    const created = bodyText.toLowerCase().includes('success') ||
                    bodyText.toLowerCase().includes('added') ||
                    bodyText.includes(uniqueZone) ||
                    url.includes('/edit') ||
                    url.includes('/zones/forward');
    expect(created).toBeTruthy();

    // Now logout and login as manager to verify visibility
    await logout(page);
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/forward?letter=all');

    const managerBodyText = await page.locator('body').textContent();
    expect(managerBodyText).toContain(uniqueZone);
  });
});
