import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

/** Fills every required field on /users/add so the post reaches the server. */
async function fillAddUserForm(page, { username, email, password }) {
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="fullname"]').fill(username);
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
}

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

      await expect(page).toHaveURL(/\/users$/);
      await expect(page.locator('[data-testid="system-message"]'))
        .toContainText(/user has been created successfully/i);
      await page.goto(`/users?search=${uniqueUsername}`);
      const row = page.locator(`tr:has(input[value="${uniqueUsername}"])`);
      await expect(row).toHaveCount(1);

      // Remove the throwaway user again
      await row.locator('a[href*="/delete"]').first().click();
      await page.locator('button[type="submit"][name="commit"]').click();
      await page.goto(`/users?search=${uniqueUsername}`);
      await expect(page.locator(`tr:has(input[value="${uniqueUsername}"])`)).toHaveCount(0);
    });

    test('should reject empty username', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users/add');

      await page.locator('input[type="password"]').first().fill(testPassword);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // The field is required, so the browser refuses to post the form at all
      await expect(page.locator('input[name="username"]'))
        .toHaveJSProperty('validity.valueMissing', true);
      await expect(page).toHaveURL(/\/users\/add/);
    });

    test('should reject duplicate username', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const stamp = Date.now();
      await page.goto('/users/add');

      // Every other field must be valid, or the browser blocks the post and the
      // duplicate is never put to the server
      await fillAddUserForm(page, {
        username: users.admin.username,
        email: `dup-username-${stamp}@example.com`,
        password: testPassword,
      });
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('[data-testid="system-message"]'))
        .toContainText(/username exist already/i);
      await expect(page).toHaveURL(/\/users\/add/);
    });

    test('should reject duplicate email address', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const stamp = Date.now();
      await page.goto('/users/add');

      await fillAddUserForm(page, {
        username: `dup-email-${stamp}`,
        email: 'admin@example.com',
        password: testPassword,
      });
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('[data-testid="system-message"]'))
        .toContainText(/email address already exists/i);
      await expect(page).toHaveURL(/\/users\/add/);
    });

    test('should reject weak password', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const stamp = Date.now();
      await page.goto('/users/add');

      await fillAddUserForm(page, {
        username: `weakpwd-${stamp}`,
        email: `weakpwd-${stamp}@example.com`,
        password: '123',
      });
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('[data-testid="system-message"]'))
        .toContainText(/password must be at least 6 characters/i);
      await expect(page).toHaveURL(/\/users\/add/);
    });
  });

  test.describe('Edit User', () => {
    test('should access edit user page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');
      await page.waitForLoadState('networkidle');

      // Use table-specific selector to avoid matching dropdown menu items
      const editLink = page.locator('table tbody a[href*="users"][href*="edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();
      await expect(page).toHaveURL(/.*\/users\/\d+\/edit/);
    });

    test('should display current user data', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');
      await page.waitForLoadState('networkidle');

      const editLink = page.locator('table tbody a[href*="users"][href*="edit"]').first();
      await expect(editLink).toBeVisible();
      await editLink.click();

      const usernameField = page.locator('input[name*="username"], input[name*="user"]').first();
      await expect(usernameField).toBeVisible();
      await expect(usernameField).not.toHaveValue('');
    });
  });

  test.describe('Delete User', () => {
    test('should access delete confirmation page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');
      await page.waitForLoadState('networkidle');

      const deleteLink = page.locator('table tbody a[href*="users"][href*="delete"]').first();
      await expect(deleteLink).toBeVisible();
      await deleteLink.click();
      await expect(page).toHaveURL(/.*\/users\/\d+\/delete/);
    });

    test('should display confirmation message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const deleteLink = page.locator('table tbody a[href*="users"][href*="delete"]').first();
      await expect(deleteLink).toBeVisible();
      await deleteLink.click();

      // Auto-retrying assertion: the click navigation may still be in flight
      await expect(page.locator('body')).toContainText(/delete|confirm|sure/i);
    });

    test('should cancel delete and return to list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/users');

      const deleteLink = page.locator('table tbody a[href*="users"][href*="delete"]').first();
      await expect(deleteLink).toBeVisible();
      await deleteLink.click();

      // delete_actions() renders the cancel control as a link, not a button
      const noBtn = page.locator('a:has-text("No"), button:has-text("No")').first();
      await expect(noBtn).toBeVisible();
      await noBtn.click();
      await expect(page).toHaveURL(/.*\/users/);
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
      await expect(templateSelector.first()).toBeVisible();
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

      // A wrong current password is refused, so admin's own password is never changed
      await page.locator('input[name="old_password"]').fill('WrongCurrentPassword');
      await page.locator('input[name="new_password"]').fill('NewPassword123!');
      await page.locator('input[name="new_password2"]').fill('NewPassword123!');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('[data-testid="system-message"]'))
        .toContainText(/did not enter the correct current password/i);
      await expect(page).toHaveURL(/\/password\/change/);
    });

    test('should reject mismatched new passwords', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/password/change');

      // The repeat field must not match, so the change is refused before it is applied
      await page.locator('input[name="old_password"]').fill(users.admin.password);
      await page.locator('input[name="new_password"]').fill('NewPassword123!');
      await page.locator('input[name="new_password2"]').fill('DifferentPassword123!');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await expect(page.locator('[data-testid="system-message"]'))
        .toContainText(/fill in all required fields correctly/i);
      await expect(page).toHaveURL(/\/password\/change/);
    });
  });
});
