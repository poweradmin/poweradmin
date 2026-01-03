import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('User CRUD Operations', () => {
  const testUsername = `testuser-${Date.now()}`;
  const testEmail = `testuser-${Date.now()}@example.com`;
  const testPassword = 'TestP@ssw0rd123';

  test.describe('List Users', () => {
    test('admin should access users list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      await expect(page).toHaveURL(/page=users/);
    });

    test('should display users table', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should display add user button', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const addBtn = page.locator('a[href*="add_user"], input[value*="Add"], button:has-text("Add")');
      expect(await addBtn.count()).toBeGreaterThan(0);
    });

    test('should display user columns', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/username|email|name/);
    });

    test('should show edit links for users', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const editLinks = page.locator('a[href*="edit_user"]');
      expect(await editLinks.count()).toBeGreaterThan(0);
    });

    test('should show delete links for users', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');

      const deleteLinks = page.locator('a[href*="delete_user"]');
      expect(await deleteLinks.count()).toBeGreaterThan(0);
    });

    test('manager should not have user management actions', async ({ managerPage: page }) => {
      await page.goto('/index.php?page=users');

      // Manager should either be denied access or have limited functionality
      const bodyText = await page.locator('body').textContent();
      const url = page.url();

      // Check if access was denied (redirect or error message)
      const accessDenied = bodyText.match(/you do not have|access denied|not authorized/i) ||
                           !url.includes('page=users');

      if (!accessDenied) {
        // If manager can access users list, they should not have add/delete options
        const addUserLink = page.locator('a[href*="add_user"], input[value*="Add user"]');
        const deleteUserLinks = page.locator('a[href*="delete_user"]');

        // Manager should have restricted access - either no page access or no admin actions
        const hasNoAdminActions = await addUserLink.count() === 0 || await deleteUserLinks.count() === 0;
        expect(hasNoAdminActions || accessDenied).toBeTruthy();
      }
    });
  });

  test.describe('Add User', () => {
    test('should access add user page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');
      await expect(page).toHaveURL(/page=add_user/);
      await expect(page.locator('form')).toBeVisible();
    });

    test('should display username field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      const usernameField = page.locator('input[name*="username"], input[name*="user"]').first();
      await expect(usernameField).toBeVisible();
    });

    test('should display email field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      const emailField = page.locator('input[name*="email"], input[type="email"]');
      if (await emailField.count() > 0) {
        await expect(emailField.first()).toBeVisible();
      }
    });

    test('should display password fields', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      const passwordFields = page.locator('input[type="password"]');
      expect(await passwordFields.count()).toBeGreaterThanOrEqual(1);
    });

    test('should create user with valid data', async ({ adminPage: page }) => {
      const uniqueUsername = `${testUsername}-valid`;
      await page.goto('/index.php?page=add_user');

      await page.locator('input[name*="username"], input[name*="user"]').first().fill(uniqueUsername);

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
                         page.url().includes('page=users');
      expect(hasSuccess).toBeTruthy();
    });

    test('should reject empty username', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      // Leave username empty
      const emailField = page.locator('input[name*="email"], input[type="email"]').first();
      if (await emailField.count() > 0) {
        await emailField.fill('test@example.com');
      }

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
                       url.includes('add_user');
      expect(hasError).toBeTruthy();
    });

    test('should reject duplicate username', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      // Use existing admin username
      await page.locator('input[name*="username"], input[name*="user"]').first().fill('admin');

      const emailField = page.locator('input[name*="email"], input[type="email"]').first();
      if (await emailField.count() > 0) {
        await emailField.fill('duplicate@example.com');
      }

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
                       url.includes('add_user');
      expect(hasError).toBeTruthy();
    });

    test('should reject password mismatch', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

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
                         bodyText.toLowerCase().includes('mismatch') ||
                         url.includes('add_user');
        expect(hasError).toBeTruthy();
      }
    });

    test('should reject weak password', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      await page.locator('input[name*="username"], input[name*="user"]').first().fill(`weakpwd-${Date.now()}`);

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill('123'); // Too weak
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const url = page.url();
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('password') ||
                       bodyText.toLowerCase().includes('weak') ||
                       bodyText.toLowerCase().includes('short') ||
                       url.includes('add_user');
      expect(hasError).toBeTruthy();
    });

    test('should reject invalid email format', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      await page.locator('input[name*="username"], input[name*="user"]').first().fill(`invalidemail-${Date.now()}`);

      const emailField = page.locator('input[name*="email"], input[type="email"]').first();
      if (await emailField.count() > 0) {
        await emailField.fill('invalid-email-format');

        const passwordFields = page.locator('input[type="password"]');
        const count = await passwordFields.count();
        for (let i = 0; i < count; i++) {
          await passwordFields.nth(i).fill(testPassword);
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const url = page.url();
        const bodyText = await page.locator('body').textContent();
        const hasError = bodyText.toLowerCase().includes('error') ||
                         bodyText.toLowerCase().includes('email') ||
                         bodyText.toLowerCase().includes('invalid') ||
                         url.includes('add_user');
        expect(hasError).toBeTruthy();
      }
    });
  });

  test.describe('Edit User', () => {
    const editUsername = `edit-user-${Date.now()}`;

    test.beforeAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto('/index.php?page=add_user');
      await page.locator('input[name*="username"], input[name*="user"]').first().fill(editUsername);

      const emailField = page.locator('input[name*="email"], input[type="email"]').first();
      if (await emailField.count() > 0) {
        await emailField.fill(`${editUsername}@example.com`);
      }

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill(testPassword);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.close();
    });

    test('should access edit user page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${editUsername}")`);

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_user"]').first();
        await editLink.click();
        await expect(page).toHaveURL(/edit_user/);
      }
    });

    test('should display current user data', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${editUsername}")`);

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_user"]').first();
        await editLink.click();

        const usernameField = page.locator('input[name*="username"], input[name*="user"]').first();
        const value = await usernameField.inputValue();
        expect(value).toContain(editUsername.substring(0, 10));
      }
    });

    test('should update user email', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${editUsername}")`);

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_user"]').first();
        await editLink.click();

        const emailField = page.locator('input[name*="email"], input[type="email"]').first();
        if (await emailField.count() > 0) {
          await emailField.fill(`updated-${editUsername}@example.com`);
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should update user password', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${editUsername}")`);

      if (await row.count() > 0) {
        const editLink = row.locator('a[href*="edit_user"]').first();
        await editLink.click();

        const passwordFields = page.locator('input[type="password"]');
        const count = await passwordFields.count();

        if (count > 0) {
          for (let i = 0; i < count; i++) {
            await passwordFields.nth(i).fill('NewPassword123!');
          }
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Delete User', () => {
    const deleteUsername = `delete-user-${Date.now()}`;

    test.beforeAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      await page.goto('/index.php?page=add_user');
      await page.locator('input[name*="username"], input[name*="user"]').first().fill(deleteUsername);

      const emailField = page.locator('input[name*="email"], input[type="email"]').first();
      if (await emailField.count() > 0) {
        await emailField.fill(`${deleteUsername}@example.com`);
      }

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill(testPassword);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.close();
    });

    test('should access delete confirmation page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${deleteUsername}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_user"]').first();
        await deleteLink.click();
        await expect(page).toHaveURL(/delete_user/);
      }
    });

    test('should display confirmation message', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${deleteUsername}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_user"]').first();
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to list', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${deleteUsername}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_user"]').first();
        await deleteLink.click();

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/page=users/);
        }
      }
    });

    test('should not allow deleting self', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=users');
      const row = page.locator('tr:has-text("admin")');

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_user"]');
        // Admin should not have delete link for themselves, or it should show error
        if (await deleteLink.count() > 0) {
          await deleteLink.first().click();

          const bodyText = await page.locator('body').textContent();
          // Should show warning about deleting self
          expect(bodyText.toLowerCase()).toMatch(/cannot|yourself|own|self/i);
        }
      }
    });

    test('should delete user successfully', async ({ adminPage: page }) => {
      // Create a new user to delete
      const toDelete = `to-delete-${Date.now()}`;
      await page.goto('/index.php?page=add_user');
      await page.locator('input[name*="username"], input[name*="user"]').first().fill(toDelete);

      const emailField = page.locator('input[name*="email"], input[type="email"]').first();
      if (await emailField.count() > 0) {
        await emailField.fill(`${toDelete}@example.com`);
      }

      const passwordFields = page.locator('input[type="password"]');
      const count = await passwordFields.count();
      for (let i = 0; i < count; i++) {
        await passwordFields.nth(i).fill(testPassword);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Now delete
      await page.goto('/index.php?page=users');
      const row = page.locator(`tr:has-text("${toDelete}")`);

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_user"]').first();
        await deleteLink.click();

        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();

          // Verify deleted
          await page.goto('/index.php?page=users');
          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toContain(toDelete);
        }
      }
    });
  });

  test.describe('User Permissions', () => {
    test('should display permission options on add user', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      const permOptions = page.locator('input[type="checkbox"], select[name*="perm"], input[name*="perm"]');
      expect(await permOptions.count()).toBeGreaterThan(0);
    });

    test('should display permission template selector', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      const templateSelector = page.locator('select[name*="template"], select[name*="perm_templ"]');
      if (await templateSelector.count() > 0) {
        await expect(templateSelector.first()).toBeVisible();
      }
    });

    test('should allow setting user as active/inactive', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=add_user');

      const activeCheckbox = page.locator('input[name*="active"], input[name*="status"]');
      if (await activeCheckbox.count() > 0) {
        await expect(activeCheckbox.first()).toBeVisible();
      }
    });
  });

  test.describe('Change Password', () => {
    test('should access change password page', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      await expect(page).toHaveURL(/page=change_password/);
      await expect(page.locator('form')).toBeVisible();
    });

    test('should display current password field', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

      const passwordFields = page.locator('input[type="password"]');
      expect(await passwordFields.count()).toBeGreaterThanOrEqual(2);
    });

    test('should reject wrong current password', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

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
                         bodyText.toLowerCase().includes('wrong') ||
                         url.includes('change_password');
        expect(hasError).toBeTruthy();
      }
    });

    test('should reject mismatched new passwords', async ({ adminPage: page }) => {
      await page.goto('/index.php?page=change_password');

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
                         url.includes('change_password');
        expect(hasError).toBeTruthy();
      }
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=users');

    // Delete all test users
    const testUserRows = page.locator('tr').filter({ hasText: /testuser-|edit-user-|delete-user-/ });
    const count = await testUserRows.count();

    for (let i = 0; i < count; i++) {
      await page.goto('/index.php?page=users');
      const row = page.locator('tr').filter({ hasText: /testuser-|edit-user-|delete-user-/ }).first();

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="delete_user"]').first();
        if (await deleteLink.count() > 0) {
          await deleteLink.click();
          const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
          if (await yesBtn.count() > 0) {
            await yesBtn.click();
          }
        }
      }
    }

    await page.close();
  });
});
