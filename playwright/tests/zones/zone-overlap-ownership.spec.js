import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard, logout } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Verifies parent_zone_ownership_check: a non-admin cannot create a zone that
// overlaps an existing zone owned by another user, but may nest under its own.
test.describe.configure({ mode: 'serial' });

test.describe('Zone overlap ownership guard', () => {
  const ts = Date.now();
  const foreignParent = `e2e-ovl-${ts}.example.com`;
  const ownParent = `e2e-mgr-${ts}.example.com`;

  async function addMasterZone(page, zoneName) {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');
    await page.locator('[data-testid="zone-name-input"]').first().fill(zoneName);
    await page.locator('[data-testid="add-zone-button"]').first().click();
    await page.waitForLoadState('networkidle');
  }

  test('admin creates a parent zone owned by admin', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await addMasterZone(page, foreignParent);

    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');
    await expect(page.locator(`tr:has-text("${foreignParent}")`).first()).toBeVisible();
  });

  test('non-owner is blocked from creating a child of that zone', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await addMasterZone(page, `child.${foreignParent}`);

    const bodyText = (await page.locator('body').textContent()).toLowerCase();
    expect(bodyText).toContain('overlaps');
    // The zone must not have been created.
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');
    await expect(page.locator(`tr:has-text("child.${foreignParent}")`)).toHaveCount(0);
  });

  test('owner may create a child under their own zone', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await addMasterZone(page, ownParent);
    await addMasterZone(page, `sub.${ownParent}`);

    const bodyText = (await page.locator('body').textContent()).toLowerCase();
    expect(bodyText).not.toContain('overlaps');
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');
    await expect(page.locator(`tr:has-text("sub.${ownParent}")`).first()).toBeVisible();
  });
});
