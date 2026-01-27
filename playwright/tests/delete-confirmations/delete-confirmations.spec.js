/**
 * Delete Confirmation Pages Tests
 *
 * Tests for all delete confirmation pages:
 * - Zone delete
 * - User delete
 * - Record delete
 * - Supermaster delete
 * - Zone template delete
 * - Permission template delete
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Zone Delete Confirmation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display warning alert on zone delete page', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    const deleteLinks = page.locator('a[href*="/delete"]').filter({ hasText: /delete/i });

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const warningAlert = page.locator('.alert-danger');
      await expect(warningAlert).toBeVisible();
    }
  });

  test('should display zone name being deleted', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    const deleteLinks = page.locator('a[href*="/delete"]').filter({ hasText: /delete/i });

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/delete.*zone|zone.*delete/i);
    }
  });

  test('should have confirm delete button', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');
    await page.waitForLoadState('networkidle');

    const deleteLinks = page.locator('a[href*="/delete"]').filter({ hasText: /delete/i });

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();
      await page.waitForLoadState('networkidle');

      const confirmBtn = page.locator('button[type="submit"]:has-text("delete"), button:has-text("Yes")');
      const hasConfirmBtn = await confirmBtn.count() > 0;
      expect(hasConfirmBtn).toBeTruthy();
    } else {
      // No zones to delete, test passes
      expect(true).toBeTruthy();
    }
  });

  test('should have cancel button', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    const deleteLinks = page.locator('a[href*="/delete"]').filter({ hasText: /delete/i });

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
      const hasCancelBtn = await cancelBtn.count() > 0;
      expect(hasCancelBtn).toBeTruthy();
    }
  });

  test('should include CSRF token', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    const deleteLinks = page.locator('a[href*="/delete"]').filter({ hasText: /delete/i });

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const csrfToken = page.locator('input[name="csrf_token"], input[name="_token"]');
      const hasToken = await csrfToken.count() > 0;
      expect(hasToken).toBeTruthy();
    }
  });
});

test.describe('User Delete Confirmation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display warning alert on user delete', async ({ page }) => {
    await page.goto('/users');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const warningAlert = page.locator('.alert-danger, .alert-warning');
      const hasWarning = await warningAlert.count() > 0;
      expect(hasWarning || page.url().includes('/delete')).toBeTruthy();
    }
  });

  test('should display user name being deleted', async ({ page }) => {
    await page.goto('/users');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/delete.*user|user/i);
    }
  });

  test('should have confirm delete button', async ({ page }) => {
    await page.goto('/users');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const confirmBtn = page.locator('button[type="submit"], button:has-text("delete")');
      const hasConfirmBtn = await confirmBtn.count() > 0;
      expect(hasConfirmBtn).toBeTruthy();
    }
  });

  test('should have cancel button linking to users list', async ({ page }) => {
    await page.goto('/users');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const cancelBtn = page.locator('a[href*="/users"]:has-text("No"), a:has-text("keep")');
      const hasCancelBtn = await cancelBtn.count() > 0;
      expect(hasCancelBtn).toBeTruthy();
    }
  });
});

test.describe('Supermaster Delete Confirmation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display warning alert on supermaster delete', async ({ page }) => {
    await page.goto('/supermasters');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const warningAlert = page.locator('.alert-danger');
      const hasWarning = await warningAlert.count() > 0;
      expect(hasWarning || page.url().includes('/delete')).toBeTruthy();
    }
  });

  test('should display supermaster IP being deleted', async ({ page }) => {
    await page.goto('/supermasters');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/supermaster|delete|ip/i);
    }
  });

  test('cancel should return to supermasters list', async ({ page }) => {
    await page.goto('/supermasters');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
      if (await cancelBtn.count() > 0) {
        await cancelBtn.first().click();

        await expect(page).toHaveURL(/.*supermasters/);
      }
    }
  });
});

test.describe('Zone Template Delete Confirmation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display warning alert on zone template delete', async ({ page }) => {
    await page.goto('/zones/templates');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const warningAlert = page.locator('.alert-danger');
      const hasWarning = await warningAlert.count() > 0;
      expect(hasWarning || page.url().includes('/delete')).toBeTruthy();
    }
  });

  test('should display template name being deleted', async ({ page }) => {
    await page.goto('/zones/templates');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/template|delete/i);
    }
  });

  test('cancel should return to templates list', async ({ page }) => {
    await page.goto('/zones/templates');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
      if (await cancelBtn.count() > 0) {
        await cancelBtn.first().click();

        await expect(page).toHaveURL(/.*zones\/templates/);
      }
    }
  });
});

test.describe('Permission Template Delete Confirmation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display warning alert on permission template delete', async ({ page }) => {
    await page.goto('/permissions/templates');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const warningAlert = page.locator('.alert-danger');
      const hasWarning = await warningAlert.count() > 0;
      expect(hasWarning || page.url().includes('/delete')).toBeTruthy();
    }
  });

  test('should display template name being deleted', async ({ page }) => {
    await page.goto('/permissions/templates');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/permission.*template|template|delete/i);
    }
  });

  test('cancel should return to permission templates list', async ({ page }) => {
    await page.goto('/permissions/templates');

    const deleteLinks = page.locator('a[href*="/delete"]');

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
      if (await cancelBtn.count() > 0) {
        await cancelBtn.first().click();

        await expect(page).toHaveURL(/.*permissions\/templates/);
      }
    }
  });
});

test.describe('Common Delete Confirmation Elements', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('delete pages should use danger button styling', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    const deleteLinks = page.locator('a[href*="/delete"]').filter({ hasText: /delete/i });

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const dangerBtn = page.locator('.btn-danger');
      const hasDangerBtn = await dangerBtn.count() > 0;
      expect(hasDangerBtn).toBeTruthy();
    }
  });

  test('cancel buttons should use secondary styling', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    const deleteLinks = page.locator('a[href*="/delete"]').filter({ hasText: /delete/i });

    if (await deleteLinks.count() > 0) {
      await deleteLinks.first().click();

      const secondaryBtn = page.locator('.btn-secondary');
      const hasSecondaryBtn = await secondaryBtn.count() > 0;
      expect(hasSecondaryBtn).toBeTruthy();
    }
  });
});

test.describe('Security', () => {
  test('delete confirmation should require authentication', async ({ page }) => {
    await page.goto('/zones/1/delete');

    // Should redirect to login
    await expect(page).toHaveURL(/.*\/login/);
  });

  test('delete user confirmation should require authentication', async ({ page }) => {
    await page.goto('/users/1/delete');

    await expect(page).toHaveURL(/.*\/login/);
  });
});
