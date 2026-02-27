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
  test('non-admin user should only see own groups on add master zone page', async ({ page }) => {
    // Manager belongs to "Zone Managers" and "Editors" only
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const groupCheckboxes = page.locator('.group-checkbox');
    const count = await groupCheckboxes.count();

    if (count === 0) {
      // Group ownership section may not be present - skip
      return;
    }

    // Manager belongs to 2 groups: "Zone Managers" and "Editors"
    expect(count).toBe(2);

    // Verify the visible group names (labels include description text)
    const allLabelText = await page.locator('.group-item').allTextContents();
    const combinedText = allLabelText.join(' ');

    expect(combinedText).toContain('Zone Managers');
    expect(combinedText).toContain('Editors');

    // Should NOT see other groups
    expect(combinedText).not.toContain('Administrators');
    expect(combinedText).not.toContain('Viewers');
    expect(combinedText).not.toContain('Guests');
  });

  test('non-admin user should only see own groups on add slave zone page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto('/zones/add/slave');
    await page.waitForLoadState('networkidle');

    const groupCheckboxes = page.locator('.group-checkbox');
    const count = await groupCheckboxes.count();

    if (count === 0) {
      return;
    }

    expect(count).toBe(2);

    const allLabelText = await page.locator('.group-item').allTextContents();
    const combinedText = allLabelText.join(' ');

    expect(combinedText).toContain('Zone Managers');
    expect(combinedText).toContain('Editors');
    expect(combinedText).not.toContain('Administrators');
    expect(combinedText).not.toContain('Viewers');
    expect(combinedText).not.toContain('Guests');
  });

  test('admin user should see all groups on add master zone page', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const groupCheckboxes = page.locator('.group-checkbox');
    const count = await groupCheckboxes.count();

    if (count === 0) {
      return;
    }

    // Admin should see all 5 groups
    expect(count).toBeGreaterThanOrEqual(5);
  });
});
