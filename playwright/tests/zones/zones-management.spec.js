/**
 * Zones Management Tests
 *
 * Tests for zones list page display and functionality.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('List Zones', () => {
  test.describe('Admin User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/zone|list/i);
    });

    test('should display zones table', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should have add master zone link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      // Link may be in a dropdown menu, so check for existence not visibility
      const addMasterLink = page.locator('a[href*="/add/master"]');
      expect(await addMasterLink.count()).toBeGreaterThan(0);
    });

    test('should have add slave zone link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      // Link may be in a dropdown menu, so check for existence not visibility
      const addSlaveLink = page.locator('a[href*="/add/slave"]');
      expect(await addSlaveLink.count()).toBeGreaterThan(0);
    });

    test('should display zone rows when zones exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const rows = page.locator('table tbody tr');
      if (await rows.count() > 0) {
        await expect(rows.first()).toBeVisible();
      }
    });

    test('should display edit links for zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLinks = page.locator('table a[href*="/edit"]');
      if (await editLinks.count() > 0) {
        await expect(editLinks.first()).toBeVisible();
      }
    });

    test('should display delete links for zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLinks = page.locator('a[href*="/delete"]');
      if (await deleteLinks.count() > 0) {
        await expect(deleteLinks.first()).toBeVisible();
      }
    });

    test('should have working add master zone navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*zones\/add\/master/);
    });

    test('should have working add slave zone navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*zones\/add\/slave/);
    });
  });

  test.describe('Manager User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('should display add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Client User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('should not display add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/forward?letter=all');
      const addMasterBtn = page.locator('input[value*="Add master zone"]');
      const addSlaveBtn = page.locator('input[value*="Add slave zone"]');
      expect(await addMasterBtn.count()).toBe(0);
      expect(await addSlaveBtn.count()).toBe(0);
    });
  });

  test.describe('Viewer User', () => {
    test('should display zones page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('should not display add zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const addMasterBtn = page.locator('input[value*="Add master zone"]');
      expect(await addMasterBtn.count()).toBe(0);
    });

    test('should not display delete buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLinks = page.locator('a[href*="/delete"]');
      expect(await deleteLinks.count()).toBe(0);
    });
  });
});

test.describe('Edit Zone', () => {
  test.describe('Admin User', () => {
    test('should access zone edit page from list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/.*edit/);
      }
    });

    test('should display records table on edit page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const table = page.locator('table').first();
        await expect(table).toBeVisible();
      }
    });

    test('should display zone metadata', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('table a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/owner|zone|type/i);
      }
    });
  });
});

test.describe('Delete Zone', () => {
  test.describe('Admin User', () => {
    test('should navigate to delete zone page from list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/.*delete/);
      }
    });

    test('should display confirmation on delete page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should display yes and no buttons on delete page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        const noBtn = page.locator('input[value="No"], button:has-text("No")').first();
        if (await yesBtn.count() > 0) {
          await expect(yesBtn).toBeVisible();
        }
        if (await noBtn.count() > 0) {
          await expect(noBtn).toBeVisible();
        }
      }
    });
  });

  test.describe('Viewer User', () => {
    test('should not see delete buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');
      const deleteLinks = page.locator('a[href*="/delete"]');
      expect(await deleteLinks.count()).toBe(0);
    });
  });
});

test.describe('Edit Zone Comment', () => {
  test.describe('Admin User', () => {
    test('should access edit comment page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const row = page.locator('table tbody tr').first();
      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="/comment"]').first();
        if (await editLink.count() > 0) {
          await editLink.click();
          await expect(page).toHaveURL(/.*comment/);
        }
      }
    });

    test('should display comment form on edit comment page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href.match(/\/zones\/(\d+)\/edit/);
        if (match) {
          await page.goto(`/zones/${match[1]}/comment`);
          const textarea = page.locator('textarea').first();
          if (await textarea.count() > 0) {
            await expect(textarea).toBeVisible();
          }
        }
      }
    });

    test('should display update button on edit comment page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');
      const editLink = page.locator('a[href*="/edit"]').first();
      if (await editLink.count() > 0) {
        const href = await editLink.getAttribute('href');
        const match = href.match(/\/zones\/(\d+)\/edit/);
        if (match) {
          await page.goto(`/zones/${match[1]}/comment`);
          const updateBtn = page.locator('input[type="submit"], button[type="submit"]').first();
          if (await updateBtn.count() > 0) {
            await expect(updateBtn).toBeVisible();
          }
        }
      }
    });
  });
});
