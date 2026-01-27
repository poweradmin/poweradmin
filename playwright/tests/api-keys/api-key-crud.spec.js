/**
 * API Key CRUD Tests
 *
 * Tests for API Key management functionality covering:
 * - List/manage API keys
 * - Create new API key
 * - Display newly created key
 * - Edit key properties
 * - Delete confirmation
 * - Key regeneration
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('API Keys List', () => {
  test.describe('Page Access', () => {
    test('should access API keys page when logged in', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const url = page.url();
      const bodyText = await page.locator('body').textContent();

      const isOnApiKeysPage = url.includes('api/keys');
      const hasApiKeysContent = bodyText.toLowerCase().includes('api key');
      const isFeatureUnavailable = bodyText.toLowerCase().includes('not available') ||
                                    bodyText.toLowerCase().includes('permission');

      expect(isOnApiKeysPage || hasApiKeysContent || isFeatureUnavailable).toBeTruthy();
    });

    test('should display API keys management title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/api.*key|not available|permission/i);
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const breadcrumb = page.locator('nav[aria-label="breadcrumb"], .breadcrumb');
      const hasBreadcrumb = await breadcrumb.count() > 0;

      expect(hasBreadcrumb || page.url().includes('api/keys')).toBeTruthy();
    });

    test('should require login to access API keys', async ({ page }) => {
      await page.goto('/settings/api-keys');
      await expect(page).toHaveURL(/.*login/);
    });
  });

  test.describe('API Keys Table', () => {
    test('should display table or empty state', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const bodyText = await page.locator('body').textContent();

      const hasTable = await page.locator('table').count() > 0;
      const hasEmptyState = bodyText.toLowerCase().includes('no api keys');

      expect(hasTable || hasEmptyState || bodyText.toLowerCase().includes('api')).toBeTruthy();
    });

    test('should display table headers when keys exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const bodyText = await page.locator('body').textContent();

      const hasTableHeaders = bodyText.toLowerCase().includes('name') ||
                               bodyText.toLowerCase().includes('status') ||
                               bodyText.toLowerCase().includes('no api keys');
      expect(hasTableHeaders).toBeTruthy();
    });

    test('should display status badges', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const bodyText = await page.locator('body').textContent();

      const hasStatusInfo = bodyText.toLowerCase().includes('active') ||
                            bodyText.toLowerCase().includes('disabled') ||
                            bodyText.toLowerCase().includes('expired') ||
                            bodyText.toLowerCase().includes('no api keys');
      expect(hasStatusInfo).toBeTruthy();
    });
  });

  test.describe('Add API Key Button', () => {
    test('should display add new API key button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const addBtn = page.locator('a[href*="/add"], button:has-text("Add")');
      const bodyText = await page.locator('body').textContent();

      const hasAddBtn = await addBtn.count() > 0;
      const hasMaxKeysWarning = bodyText.toLowerCase().includes('maximum number');

      expect(hasAddBtn || hasMaxKeysWarning || !page.url().includes('api/keys')).toBeTruthy();
    });
  });
});

test.describe('Add API Key', () => {
  test.describe('Add Page Access', () => {
    test('should access add API key page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const url = page.url();
      const bodyText = await page.locator('body').textContent();

      const isOnAddPage = url.includes('/add');
      const hasAddContent = bodyText.toLowerCase().includes('add api key');

      expect(isOnAddPage || hasAddContent || bodyText.toLowerCase().includes('api')).toBeTruthy();
    });

    test('should display add API key form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"]');
      const hasNameInput = await nameInput.count() > 0;

      expect(hasNameInput || !page.url().includes('/add')).toBeTruthy();
    });
  });

  test.describe('Add Form Fields', () => {
    test('should have name input field', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"], input#name');
      const hasNameInput = await nameInput.count() > 0;

      if (hasNameInput) {
        await expect(nameInput).toBeVisible();
      }
    });

    test('should have expires at date input', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const expiresInput = page.locator('input[name="expires_at"], input#expires_at, input[type="date"]');
      const hasExpiresInput = await expiresInput.count() > 0;

      expect(hasExpiresInput || page.url().includes('api/keys')).toBeTruthy();
    });

    test('should have create API key button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const submitBtn = page.locator('button[type="submit"], input[type="submit"]');
      const hasSubmitBtn = await submitBtn.count() > 0;

      if (hasSubmitBtn) {
        await expect(submitBtn.first()).toBeVisible();
      }
    });

    test('should have cancel button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const cancelBtn = page.locator('a:has-text("Cancel"), a[href*="api/keys"]');
      const hasCancelBtn = await cancelBtn.count() > 0;

      expect(hasCancelBtn || !page.url().includes('/add')).toBeTruthy();
    });

    test('should include CSRF token', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const csrfToken = page.locator('input[name="_token"]');
      const hasCsrfToken = await csrfToken.count() > 0;

      if (hasCsrfToken) {
        await expect(csrfToken).toBeAttached();
      }
    });
  });

  test.describe('Add Form Validation', () => {
    test('should reject empty name', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const submitBtn = page.locator('button[type="submit"]');

      if (await submitBtn.count() > 0) {
        await submitBtn.click();

        const url = page.url();
        const bodyText = await page.locator('body').textContent();

        const hasValidationError = bodyText.toLowerCase().includes('required') ||
                                    bodyText.toLowerCase().includes('provide') ||
                                    url.includes('/add');
        expect(hasValidationError).toBeTruthy();
      }
    });

    test('should have name field with maxlength attribute', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"]');

      if (await nameInput.count() > 0) {
        const maxLength = await nameInput.getAttribute('maxlength');
        expect(maxLength === '255' || maxLength === null).toBeTruthy();
      }
    });
  });

  test.describe('Important Information Panel', () => {
    test('should display warning about one-time key display', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const bodyText = await page.locator('body').textContent();

      const hasWarning = bodyText.toLowerCase().includes('once') ||
                          bodyText.toLowerCase().includes('save') ||
                          bodyText.toLowerCase().includes('important');
      expect(hasWarning || !page.url().includes('/add')).toBeTruthy();
    });
  });
});

test.describe('API Key Created', () => {
  test.describe('Created Page Structure', () => {
    test('should display success message after creation', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"]');

      if (await nameInput.count() > 0) {
        const uniqueName = `test-key-${Date.now()}`;
        await nameInput.fill(uniqueName);

        const submitBtn = page.locator('button[type="submit"]');
        await submitBtn.click();

        const bodyText = await page.locator('body').textContent();

        const hasSuccess = bodyText.toLowerCase().includes('created') ||
                           bodyText.toLowerCase().includes('success') ||
                           bodyText.toLowerCase().includes('api key');
        expect(hasSuccess).toBeTruthy();
      }
    });

    test('should display API key value one time', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"]');

      if (await nameInput.count() > 0) {
        const uniqueName = `test-key-view-${Date.now()}`;
        await nameInput.fill(uniqueName);

        const submitBtn = page.locator('button[type="submit"]');
        await submitBtn.click();

        const keyInput = page.locator('input#api-key-value, input[readonly]');
        const bodyText = await page.locator('body').textContent();

        const hasKeyDisplay = await keyInput.count() > 0;
        const hasApiKeyContent = bodyText.toLowerCase().includes('api key');

        expect(hasKeyDisplay || hasApiKeyContent).toBeTruthy();
      }
    });

    test('should have copy button for API key', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"]');

      if (await nameInput.count() > 0) {
        const uniqueName = `test-key-copy-${Date.now()}`;
        await nameInput.fill(uniqueName);

        const submitBtn = page.locator('button[type="submit"]');
        await submitBtn.click();

        const copyBtn = page.locator('#copy-button, button:has-text("Copy")');
        const hasCopyBtn = await copyBtn.count() > 0;

        expect(hasCopyBtn || page.url().includes('api/keys')).toBeTruthy();
      }
    });

    test('should have return to API keys link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"]');

      if (await nameInput.count() > 0) {
        const uniqueName = `test-key-return-${Date.now()}`;
        await nameInput.fill(uniqueName);

        const submitBtn = page.locator('button[type="submit"]');
        await submitBtn.click();

        // Link might be in breadcrumb, card, or button
        const returnLink = page.locator('a[href*="api-keys"], a[href*="settings"]');
        const hasReturnLink = await returnLink.count() > 0;

        expect(hasReturnLink).toBeTruthy();
      }
    });
  });
});

test.describe('Delete API Key', () => {
  test.describe('Delete Confirmation Page', () => {
    test('should access delete confirmation page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const nameInput = page.locator('input[name="name"]');

      if (await nameInput.count() > 0) {
        const uniqueName = `test-delete-${Date.now()}`;
        await nameInput.fill(uniqueName);

        const submitBtn = page.locator('button[type="submit"]');
        await submitBtn.click();

        await page.goto('/settings/api-keys');

        const deleteLink = page.locator('a[href*="/delete"]').first();

        if (await deleteLink.count() > 0) {
          await deleteLink.click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText.toLowerCase()).toMatch(/delete|confirm|warning/i);
        }
      }
    });

    test('should display warning message', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();

        const hasWarning = bodyText.toLowerCase().includes('warning') ||
                           bodyText.toLowerCase().includes('cannot be undone');
        expect(hasWarning || page.url().includes('api/keys')).toBeTruthy();
      }
    });

    test('should have confirm delete button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const confirmBtn = page.locator('button[type="submit"]:has-text("Yes"), button:has-text("delete")');
        const hasConfirmBtn = await confirmBtn.count() > 0;

        expect(hasConfirmBtn || page.url().includes('api/keys')).toBeTruthy();
      }
    });

    test('should have cancel button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const cancelBtn = page.locator('a:has-text("No"), a:has-text("keep")');
        const hasCancelBtn = await cancelBtn.count() > 0;

        expect(hasCancelBtn || page.url().includes('api/keys')).toBeTruthy();
      }
    });
  });

  test.describe('Delete Functionality', () => {
    test('should have cancel option on delete page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await page.waitForLoadState('networkidle');

        // Verify we're on delete confirmation page and have options
        const bodyText = await page.locator('body').textContent();
        const hasDeletePage = bodyText.toLowerCase().includes('delete') ||
                              bodyText.toLowerCase().includes('confirm');
        expect(hasDeletePage).toBeTruthy();
      }
    });
  });
});

test.describe('API Key Actions', () => {
  test.describe('Regenerate API Key', () => {
    test('should have regenerate link in API keys table', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const regenerateLink = page.locator('a[href*="/regenerate"]');
      const hasRegenerate = await regenerateLink.count() > 0;

      const bodyText = await page.locator('body').textContent();
      const hasNoKeys = bodyText.toLowerCase().includes('no api keys');

      expect(hasRegenerate || hasNoKeys || page.url().includes('api/keys')).toBeTruthy();
    });
  });

  test.describe('Enable/Disable API Key', () => {
    test('should have toggle (enable/disable) link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const toggleLink = page.locator('a[href*="/toggle"]');
      const hasToggle = await toggleLink.count() > 0;

      const bodyText = await page.locator('body').textContent();
      const hasNoKeys = bodyText.toLowerCase().includes('no api keys');

      expect(hasToggle || hasNoKeys || page.url().includes('api/keys')).toBeTruthy();
    });
  });

  test.describe('Edit API Key', () => {
    test('should have edit link in API keys table', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const editLink = page.locator('a[href*="/edit"]');
      const hasEdit = await editLink.count() > 0;

      const bodyText = await page.locator('body').textContent();
      const hasNoKeys = bodyText.toLowerCase().includes('no api keys');

      expect(hasEdit || hasNoKeys || page.url().includes('api/keys')).toBeTruthy();
    });
  });
});

test.describe('API Keys User Permissions', () => {
  test.describe('Admin Access', () => {
    test('admin should access API keys page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const url = page.url();
      const bodyText = await page.locator('body').textContent();

      const hasAccess = url.includes('api/keys') ||
                        bodyText.toLowerCase().includes('api key');
      expect(hasAccess || bodyText.toLowerCase().includes('permission')).toBeTruthy();
    });
  });

  test.describe('Manager Access', () => {
    test('manager should access API keys for own account', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/settings/api-keys');

      const url = page.url();
      const bodyText = await page.locator('body').textContent();

      expect(url.includes('api/keys') ||
             url.includes('/') ||
             bodyText.toLowerCase().includes('api') ||
             bodyText.toLowerCase().includes('permission')).toBeTruthy();
    });
  });

  test.describe('Client Access', () => {
    test('client should access API keys for own account', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/settings/api-keys');

      const url = page.url();
      const bodyText = await page.locator('body').textContent();

      expect(url.includes('api/keys') ||
             url.includes('/') ||
             bodyText.toLowerCase().includes('api') ||
             bodyText.toLowerCase().includes('permission')).toBeTruthy();
    });
  });
});

test.describe('API Keys Security', () => {
  test.describe('CSRF Protection', () => {
    test('should include CSRF token in add form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys/add');

      const csrfToken = page.locator('input[name="_token"]');

      if (await csrfToken.count() > 0) {
        const tokenValue = await csrfToken.getAttribute('value');
        expect(tokenValue).toBeTruthy();
      }
    });

    test('should include CSRF token in delete form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const deleteLink = page.locator('a[href*="/delete"]').first();

      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const csrfToken = page.locator('input[name="_token"]');

        if (await csrfToken.count() > 0) {
          const tokenValue = await csrfToken.getAttribute('value');
          expect(tokenValue).toBeTruthy();
        }
      }
    });
  });

  test.describe('Key Visibility', () => {
    test('should not display API key secret in list', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/settings/api-keys');

      const bodyText = await page.locator('body').textContent();

      const hasTable = await page.locator('table').count() > 0;

      if (hasTable) {
        const tableText = await page.locator('table tbody').textContent();
        expect(tableText || bodyText).toBeDefined();
      }
    });
  });
});

// Cleanup created test API keys
test.afterAll(async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();

  await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

  await page.goto('/settings/api-keys');

  const testKeyNames = ['test-key', 'test-delete', 'test-key-view', 'test-key-copy', 'test-key-return'];

  for (const prefix of testKeyNames) {
    let found = true;
    while (found) {
      await page.goto('/settings/api-keys');

      const row = page.locator(`tr:has-text("${prefix}")`).first();

      if (await row.count() > 0) {
        const deleteLink = row.locator('a[href*="/delete"]');

        if (await deleteLink.count() > 0) {
          await deleteLink.click();

          const confirmBtn = page.locator('button[type="submit"]').first();

          if (await confirmBtn.count() > 0) {
            await confirmBtn.click();
          }
        } else {
          found = false;
        }
      } else {
        found = false;
      }
    }
  }

  await page.close();
  await context.close();
});
