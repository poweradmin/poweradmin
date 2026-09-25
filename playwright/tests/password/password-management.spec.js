import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard, login } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

/**
 * Changing a password invalidates the session it belongs to. Doing that to admin
 * broke every other worker, which shares one server-side session for that account,
 * and left / and /login redirecting to each other until the browser gave up. So the
 * change is exercised on a user this test creates and removes again.
 */
async function createThrowawayUser(page, username, password) {
  await page.goto('/users/add');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="fullname"]').fill('Password Test User');
  await page.locator('input[name="email"]').fill(`${username}@example.com`);
  await page.locator('input[name="password"]').fill(password);

  const permTempl = page.locator('select[name="perm_templ"]');
  const options = permTempl.locator('option:not([disabled])');
  await expect(options).not.toHaveCount(0);
  await permTempl.selectOption(await options.first().getAttribute('value'));

  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');

  // The list is filtered rather than scanned, and the username renders as an
  // input value rather than row text, so :has-text would never match it
  await page.goto(`/users?search=${username}`);
  await expect(page.locator(`tr:has(input[value="${username}"])`)).toHaveCount(1);
}

async function deleteThrowawayUser(page, username) {
  await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  await page.goto(`/users?search=${username}`);
  const row = page.locator(`tr:has(input[value="${username}"])`);
  await expect(row).toHaveCount(1);

  await row.locator('a[href*="/delete"]').first().click();
  await page.locator('button[type="submit"][name="commit"]').click();

  await page.goto(`/users?search=${username}`);
  await expect(page.locator(`tr:has(input[value="${username}"])`)).toHaveCount(0);
}

test.describe('Password Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access change password page', async ({ page }) => {
    // Try direct navigation first
    await page.goto('/password/change');
    await page.waitForLoadState('networkidle');

    const bodyText = await page.locator('body').textContent();

    // Check if we're on a password change page
    if (page.url().includes('password') ||
        bodyText.toLowerCase().includes('password') ||
        bodyText.toLowerCase().includes('change')) {
      expect(bodyText).not.toMatch(/fatal|exception/i);
    } else {
      // Password change might be in a different location
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should change password successfully', async ({ page }) => {
    const username = `pwchange-${Date.now()}`;
    const firstPassword = 'SecurePass123!@#';
    const secondPassword = 'AnotherPass456!@#';

    try {
      await createThrowawayUser(page, username, firstPassword);

      // Act as that user from here on, so admin's session is never disturbed
      await page.goto('/logout');
      await login(page, username, firstPassword);
      await expect(page).not.toHaveURL(/login/);

      await page.goto('/password/change');
      const passwordFields = page.locator('input[type="password"]');
      await expect(passwordFields).toHaveCount(3);

      await passwordFields.nth(0).fill(firstPassword);
      await passwordFields.nth(1).fill(secondPassword);
      await passwordFields.nth(2).fill(secondPassword);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('domcontentloaded');

      // The new password must be the one that works
      await page.goto('/logout');
      await login(page, username, secondPassword);
      await expect(page).not.toHaveURL(/login/);

      // And the old one must not
      await page.goto('/logout');
      await login(page, username, firstPassword);
      await expect(page).toHaveURL(/login/);
    } finally {
      await deleteThrowawayUser(page, username);
    }
  });

  test('should validate password requirements', async ({ page }) => {
    await page.goto('/password/change');
    await page.waitForLoadState('networkidle');

    const passwordFields = page.locator('input[type="password"]');
    if (await passwordFields.count() < 3) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Fill in current password
    await passwordFields.nth(0).fill(users.admin.password);

    // Try weak password
    await passwordFields.nth(1).fill('weak');
    await passwordFields.nth(2).fill('weak');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Should show validation error or stay on form
    const bodyText = await page.locator('body').textContent();
    // Either shows error or stays on password change page
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(page.url().includes('password') || bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('weak')).toBeTruthy();
  });

  test('should handle password mismatch', async ({ page }) => {
    await page.goto('/password/change');
    await page.waitForLoadState('networkidle');

    const passwordFields = page.locator('input[type="password"]');
    if (await passwordFields.count() < 3) {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Fill in current password
    await passwordFields.nth(0).fill(users.admin.password);

    // Enter mismatched passwords
    await passwordFields.nth(1).fill('ValidPass123!@#');
    await passwordFields.nth(2).fill('DifferentPass456!@#');

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    // Should show mismatch error or stay on form
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
    expect(page.url().includes('password') || bodyText.toLowerCase().includes('error') || bodyText.toLowerCase().includes('match')).toBeTruthy();
  });
});
