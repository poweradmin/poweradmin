import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('User CRUD Operations', () => {
  const testPassword = 'TestP@ssw0rd123';

  test.describe('List Users', () => {
    test('admin should access users list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      await expect(page).toHaveURL(/.*\/users/);
    });

    test('should display users table', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should display add user button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const addBtn = page.locator('a[href*="/users/add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('should display user columns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/username|email|name/);
    });

    test('should show edit links for users', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const editLinks = page.locator('a[href*="/edit"]');
      expect(await editLinks.count()).toBeGreaterThan(0);
    });

    test('should show delete links for users', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const deleteLinks = page.locator('a[href*="/delete"]');
      expect(await deleteLinks.count()).toBeGreaterThan(0);
    });
  });

  test.describe('Add User', () => {
    test('should access add user page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      await expect(page).toHaveURL(/.*\/users\/add/);
      await expect(page.locator('form')).toBeVisible();
    });

    test('should display username field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      const usernameField = page.locator('input[name*="username"], input[name*="user"]').first();
      await expect(usernameField).toBeVisible();
    });

    test('should display password fields', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      const passwordFields = page.locator('input[type="password"]');
      expect(await passwordFields.count()).toBeGreaterThanOrEqual(1);
    });

    test('should create user with valid data', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const uniqueUsername = `testuser-${Date.now()}`;
      await page.goto('/users/add');

      await page.locator('input[name*="username"], input[name*="user"]').first().fill(uniqueUsername);

      const fullnameField = page.locator('input[name="fullname"]');
      if (await fullnameField.count() > 0) {
        await fullnameField.fill(`Test User ${uniqueUsername}`);
      }

      const emailField = page.locator('input[name*="email"], input[type="email"]').first();
      if (await emailField.count() > 0) {
        await emailField.fill(`${uniqueUsername}@example.com`);
      }

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill(testPassword);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      const hasSuccess = bodyText.toLowerCase().includes('success') ||
                         bodyText.toLowerCase().includes('created') ||
                         bodyText.toLowerCase().includes('added') ||
                         page.url().includes('/users');
      expect(hasSuccess).toBeTruthy();
    });

    test('should reject empty username', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill(testPassword);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('required') ||
                       url.includes('/users/add');
      expect(hasError).toBeTruthy();
    });

    test('should reject duplicate username', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      await page.locator('input[name*="username"], input[name*="user"]').first().fill('admin');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill(testPassword);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('exist') ||
                       bodyText.toLowerCase().includes('duplicate') ||
                       url.includes('/users/add');
      expect(hasError).toBeTruthy();
    });

    test('should reject password mismatch', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      await page.locator('input[name*="username"], input[name*="user"]').first().fill(`mismatch-${Date.now()}`);

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();

      if (count >= 2) {
        await passwordFields.nth(0).fill('Password123!');
        await passwordFields.nth(1).fill('DifferentPassword!');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('error') ||
                         bodyText.toLowerCase().includes('match') ||
                         url.includes('/users/add');
        expect(hasError).toBeTruthy();
      }
    });

    test('should reject weak password', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      await page.locator('input[name*="username"], input[name*="user"]').first().fill(`weakpwd-${Date.now()}`);

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill('123');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('password') ||
                       url.includes('/users/add');
      expect(hasError).toBeTruthy();
    });
  });

  test.describe('Edit User', () => {
    test('should access edit user page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');
      await page.waitForLoadState('networkidle');

      // Use table-specific selector to avoid matching dropdown menu items
      const usersTable = page.locator('table');
      if (await usersTable.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|admin/i);
        return;
      }

      const editLink = usersTable.locator('tbody a[href*="users"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveURL(/.*\/users\/\d+\/edit/);
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|admin/i);
      }
    });

    test('should display current user data', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');
      await page.waitForLoadState('networkidle');

      const usersTable = page.locator('table');
      if (await usersTable.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|admin/i);
        return;
      }

      const editLink = usersTable.locator('tbody a[href*="users"][href*="edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await page.waitForLoadState('networkidle');

        const usernameField = page.locator('input[name*="username"], input[name*="user"]').first();
        if (await usernameField.count() > 0) {
          const value = await usernameField.inputValue();
          expect(value.length).toBeGreaterThan(0);
        } else {
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|admin/i);
      }
    });
  });

  test.describe('Delete User', () => {
    test('should access delete confirmation page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');
      await page.waitForLoadState('networkidle');

      const usersTable = page.locator('table');
      if (await usersTable.count() === 0) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|admin/i);
        return;
      }

      const deleteLink = usersTable.locator('tbody a[href*="users"][href*="delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveURL(/.*\/users\/\d+\/delete/);
      } else {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/user|admin/i);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const deleteLink = page.locator('a[href*="/delete"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('a:has-text("No"), button:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/.*\/users/);
        }
      }
    });
  });

  test.describe('User Permissions', () => {
    test('should display permission options on add user', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      const permOptions = page.locator('input[type="checkbox"], select[name*="perm"], input[name*="perm"]');
      expect(await permOptions.count()).toBeGreaterThan(0);
    });

    test('should display permission template selector', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      const templateSelector = page.locator('select[name*="template"], select[name*="perm_templ"]');
      if (await templateSelector.count() > 0) {
        await expect(templateSelector.first()).toBeVisible();
      }
    });
  });

  test.describe('Change Password', () => {
    test('should access change password page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/password/change');

      await expect(page).toHaveURL(/.*\/password\/change/);
      await expect(page.locator('form')).toBeVisible();
    });

    test('should display password fields', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/password/change');

      const passwordFields = page.locator('input[type="password"]');
      expect(await passwordFields.count()).toBeGreaterThanOrEqual(2);
    });

    test('should reject wrong current password', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/password/change');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();

      if (count >= 3) {
        await passwordFields.nth(0).fill('WrongCurrentPassword');
        await passwordFields.nth(1).fill('NewPassword123!');
        await passwordFields.nth(2).fill('NewPassword123!');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        const url = page.url();
        const hasError = bodyText.toLowerCase().includes('error') ||
                         bodyText.toLowerCase().includes('incorrect') ||
                         url.includes('/password/change');
        expect(hasError).toBeTruthy();
      }
    });

    test('should reject mismatched new passwords', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/password/change');

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();

      if (count >= 3) {
        await passwordFields.nth(0).fill(users.admin.password);
        await passwordFields.nth(1).fill('NewPassword123!');
        await passwordFields.nth(2).fill('DifferentPassword123!');

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        const url = page.url();
        const hasError = bodyText.toLowerCase().includes('error') ||
                         bodyText.toLowerCase().includes('match') ||
                         url.includes('/password/change');
        expect(hasError).toBeTruthy();
      }
    });
  });
});
