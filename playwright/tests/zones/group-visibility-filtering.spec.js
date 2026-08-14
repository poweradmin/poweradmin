/**
 * Group Visibility Filtering Tests
 *
 * Verifies that non-admin users only see groups they belong to
 * when creating zones or managing zone ownership (closes #1043).
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Group Visibility Filtering', () => {
  // Compare the exact set of offered groups. data-groupname carries the name on its
  // own, so this does not trip over the member-count badge or the description.
  async function visibleGroupNames(page) {
    await expect(page.locator('#group_list')).toBeVisible();
    return (await page.locator('.group-item').evaluateAll(
      items => items.map(item => item.dataset.groupname)
    )).sort();
  }

  test('non-admin user should only see own groups on add master zone page', async ({ page }) => {
    // The fixture puts manager in Zone Managers and Editors, and nothing else.
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    expect(await visibleGroupNames(page)).toEqual(['editors', 'zone managers']);
  });

  test('non-admin user should only see own groups on add slave zone page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/add/slave');
    await page.waitForLoadState('networkidle');

    expect(await visibleGroupNames(page)).toEqual(['editors', 'zone managers']);
  });

  test('admin user should see all groups on add master zone page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    // Admin sees every seeded group, and may see extras left by other suites.
    expect(await visibleGroupNames(page)).toEqual(
      expect.arrayContaining(['administrators', 'editors', 'guests', 'viewers', 'zone managers'])
    );
  });

  test('should display member count badges next to group names on add master zone page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const groupItems = page.locator('.group-item');
    await expect(groupItems).not.toHaveCount(0);

    // Each group label should contain a member count badge with "members" text
    const badgeText = await groupItems.first().locator('label .badge').textContent();
    expect(badgeText).toMatch(/\d+\s+members/i);
  });

  test('should display member count badges next to group names on add slave zone page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/add/slave');
    await page.waitForLoadState('networkidle');

    const groupItems = page.locator('.group-item');
    await expect(groupItems).not.toHaveCount(0);

    const badgeText = await groupItems.first().locator('label .badge').textContent();
    expect(badgeText).toMatch(/\d+\s+members/i);
  });
});
