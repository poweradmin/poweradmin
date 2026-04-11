/**
 * API Logs Tests
 *
 * Tests for API Activity Logs page covering:
 * - Page access and navigation
 * - Filter controls (user, event type, date range)
 * - Log entries display after generating API key events
 * - Export functionality
 * - Permission checks for non-admin users
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Serial mode: we create API keys to generate log entries, then verify them
test.describe.configure({ mode: 'serial' });

test.describe('API Logs - Generate Events', () => {
  test('should create an API key (api_key_create)', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api-keys/add');

    const nameInput = page.locator('input[name="name"]');
    await nameInput.fill('test-api-logs-key');

    const submitBtn = page.locator('button[type="submit"]');
    await submitBtn.click();

    const bodyText = await page.locator('body').textContent();
    expect(bodyText.toLowerCase()).toMatch(/created|success|api key/);
  });

  test('should edit the API key (api_key_edit)', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api-keys');

    const row = page.locator('tr:has-text("test-api-logs-key")').first();
    const editLink = row.locator('a[href*="/edit"]');

    if (await editLink.count() > 0) {
      await editLink.click();

      const nameInput = page.locator('input[name="name"]');
      await nameInput.fill('test-api-logs-key-edited');

      const submitBtn = page.locator('button[type="submit"]');
      await submitBtn.click();

      await expect(page).toHaveURL(/.*api-keys/);
    }
  });

  test('should toggle the API key (api_key_toggle)', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api-keys');

    const row = page.locator('tr:has-text("test-api-logs-key")').first();
    const toggleBtn = row.locator('button[onclick*="Modal"], form button[type="submit"]').first();

    if (await toggleBtn.count() > 0) {
      await toggleBtn.click();

      // Handle modal confirmation if present
      const modalConfirm = page.locator('.modal.show button[type="submit"]');
      if (await modalConfirm.count() > 0) {
        await modalConfirm.click();
      }

      await page.waitForURL(/.*api-keys/);
    }
  });

  test('should regenerate the API key (api_key_regenerate)', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api-keys');

    const row = page.locator('tr:has-text("test-api-logs-key")').first();
    const regenerateLink = row.locator('a[href*="/regenerate"]');

    if (await regenerateLink.count() > 0) {
      await regenerateLink.click();

      // Confirm regeneration
      const confirmBtn = page.locator('button[type="submit"]').first();
      if (await confirmBtn.count() > 0) {
        await confirmBtn.click();
      }

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/regenerated|new.*key|api key/);
    }
  });

  test('should delete the API key (api_key_delete)', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api-keys');

    const row = page.locator('tr:has-text("test-api-logs-key")').first();
    const deleteLink = row.locator('a[href*="/delete"]');

    if (await deleteLink.count() > 0) {
      await deleteLink.click();

      // Confirm deletion
      const confirmBtn = page.locator('button[type="submit"]').first();
      if (await confirmBtn.count() > 0) {
        await confirmBtn.click();
      }

      await expect(page).toHaveURL(/.*api-keys/);
    }
  });

  test('should have all event types in API logs', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api/logs');

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/api_key_create/);
    expect(bodyText).toMatch(/api_key_edit/);
    expect(bodyText).toMatch(/api_key_toggle/);
    expect(bodyText).toMatch(/api_key_regenerate/);
    expect(bodyText).toMatch(/api_key_delete/);
  });
});

test.describe('API Logs - Page Access', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access API logs page', async ({ page }) => {
      await page.goto('/settings/api/logs');
      await expect(page).toHaveURL(/.*settings\/api\/logs/);
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/api|log/i);
    });

    test('should display API Activity Logs heading', async ({ page }) => {
      await page.goto('/settings/api/logs');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/API Activity Logs/);
    });

    test('should display breadcrumb navigation', async ({ page }) => {
      await page.goto('/settings/api/logs');
      const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
      await expect(breadcrumb).toBeVisible();

      const breadcrumbText = await breadcrumb.textContent();
      expect(breadcrumbText).toMatch(/Home/);
      expect(breadcrumbText).toMatch(/API Logs/);
    });

    test('should require login to access', async ({ page }) => {
      // Use a fresh context without login
      const context = await page.context().browser().newContext();
      const freshPage = await context.newPage();
      await freshPage.goto('/settings/api/logs');
      await expect(freshPage).toHaveURL(/.*login/);
      await freshPage.close();
      await context.close();
    });
  });
});

test.describe('API Logs - Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should have API Logs link in Tools menu', async ({ page }) => {
    await page.goto('/settings/api/logs');

    // Navigate to a page with the nav bar (not dashboard)
    const apiLogsLink = page.locator('a[href*="/settings/api/logs"]');
    const hasLink = await apiLogsLink.count() > 0;

    // The link exists in the page's navigation
    expect(hasLink).toBeTruthy();
  });

  test('should navigate to API logs from Tools dropdown', async ({ page }) => {
    // Go to any non-dashboard page to get the top nav
    await page.goto('/users');

    const toolsToggle = page.locator('a.dropdown-toggle:has-text("Tools")');

    if (await toolsToggle.count() > 0) {
      await toolsToggle.click();
      await page.waitForTimeout(300);

      const apiLogsLink = page.locator('.dropdown-menu a[href*="/settings/api/logs"]');

      if (await apiLogsLink.count() > 0) {
        await apiLogsLink.click();
        await expect(page).toHaveURL(/.*settings\/api\/logs/);
      }
    }
  });
});

test.describe('API Logs - Filter Controls', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api/logs');
  });

  test('should display search form', async ({ page }) => {
    const form = page.locator('form').first();
    await expect(form).toBeVisible();
  });

  test('should have user filter dropdown', async ({ page }) => {
    const userSelect = page.locator('select[name="name"]');
    await expect(userSelect).toBeVisible();
  });

  test('should have event type filter dropdown', async ({ page }) => {
    const eventSelect = page.locator('select[name="event_type"]');
    await expect(eventSelect).toBeVisible();
  });

  test('should have event type options for API key operations', async ({ page }) => {
    const eventSelect = page.locator('select[name="event_type"]');
    const options = eventSelect.locator('option');
    const count = await options.count();

    // Should have "All events" + at least some api_key event types
    expect(count).toBeGreaterThan(1);

    const allText = await eventSelect.textContent();
    expect(allText).toMatch(/api_key_create/);
  });

  test('should have date range filters', async ({ page }) => {
    const dateFrom = page.locator('input[name="date_from"]');
    const dateTo = page.locator('input[name="date_to"]');
    await expect(dateFrom).toBeVisible();
    await expect(dateTo).toBeVisible();
  });

  test('should display submit button', async ({ page }) => {
    const submitBtn = page.locator('button[type="submit"]').first();
    await expect(submitBtn).toBeVisible();
  });

  test('should display clear button', async ({ page }) => {
    const clearBtn = page.locator('form a.btn[href*="/settings/api/logs"]');
    if (await clearBtn.count() > 0) {
      await expect(clearBtn).toBeVisible();
    }
  });

  test('should submit filter form', async ({ page }) => {
    const submitBtn = page.locator('button[type="submit"]').first();
    await submitBtn.click();
    await expect(page).toHaveURL(/settings\/api\/logs/);
  });

  test('should filter by event type', async ({ page }) => {
    const eventSelect = page.locator('select[name="event_type"]');
    const options = eventSelect.locator('option');
    const count = await options.count();

    if (count > 1) {
      const value = await options.nth(1).getAttribute('value');
      await eventSelect.selectOption(value);
      await page.locator('button[type="submit"]').first().click();
      await expect(page).toHaveURL(/event_type=/);
    }
  });

  test('should filter by user', async ({ page }) => {
    const userSelect = page.locator('select[name="name"]');
    const options = userSelect.locator('option');
    const count = await options.count();

    if (count > 1) {
      const value = await options.nth(1).getAttribute('value');
      await userSelect.selectOption(value);
      await page.locator('button[type="submit"]').first().click();
      await expect(page).toHaveURL(/name=/);
    }
  });
});

test.describe('API Logs - Log Entries', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should display logs table or no logs message', async ({ page }) => {
    await page.goto('/settings/api/logs');
    const table = page.locator('table').first();
    const bodyText = await page.locator('body').textContent();

    if (await table.count() > 0) {
      await expect(table).toBeVisible();
    } else {
      expect(bodyText.toLowerCase()).toMatch(/no.*log|no logs found/i);
    }
  });

  test('should display log entries from API key creation', async ({ page }) => {
    await page.goto('/settings/api/logs');
    const rows = page.locator('table tbody tr');

    if (await rows.count() > 0) {
      await expect(rows.first()).toBeVisible();

      // Should show api_key_create badge
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/api_key_create/);
    }
  });

  test('should display total logs count', async ({ page }) => {
    await page.goto('/settings/api/logs');
    const badge = page.locator('.badge.bg-secondary');
    await expect(badge).toBeVisible();

    const badgeText = await badge.textContent();
    const count = parseInt(badgeText.trim());
    expect(count).toBeGreaterThanOrEqual(0);
  });

  test('should display operation badges with colors', async ({ page }) => {
    await page.goto('/settings/api/logs');
    const badges = page.locator('table .badge');

    if (await badges.count() > 0) {
      const firstBadge = badges.first();
      await expect(firstBadge).toBeVisible();
    }
  });

  test('should have details button for log entries', async ({ page }) => {
    await page.goto('/settings/api/logs');
    const detailsBtn = page.locator('table button[data-bs-toggle="modal"]');

    if (await detailsBtn.count() > 0) {
      await expect(detailsBtn.first()).toBeVisible();
    }
  });

  test('should open details modal when clicking details button', async ({ page }) => {
    await page.goto('/settings/api/logs');
    const detailsBtn = page.locator('table button[data-bs-toggle="modal"]').first();

    if (await detailsBtn.count() > 0) {
      await detailsBtn.click();

      const modal = page.locator('#apiLogModal');
      await expect(modal).toBeVisible();

      const modalText = await modal.textContent();
      expect(modalText).toMatch(/Log Details/);
    }
  });
});

test.describe('API Logs - Export', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/settings/api/logs');
  });

  test('should display export button when logs exist', async ({ page }) => {
    const rows = page.locator('table tbody tr');

    if (await rows.count() > 0) {
      const exportBtn = page.locator('button[data-bs-target="#exportModal"]');
      await expect(exportBtn).toBeVisible();
    }
  });

  test('should open export modal', async ({ page }) => {
    const exportBtn = page.locator('button[data-bs-target="#exportModal"]');

    if (await exportBtn.count() > 0) {
      await exportBtn.click();

      const modal = page.locator('#exportModal');
      await expect(modal).toBeVisible();

      const modalText = await modal.textContent();
      expect(modalText).toMatch(/Export Logs/);
      expect(modalText).toMatch(/CSV/);
      expect(modalText).toMatch(/JSON/);
    }
  });
});

test.describe('API Logs - Permission Checks', () => {
  test.describe('Manager User', () => {
    test('should not have access to API logs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/settings/api/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('settings/api/logs')).toBeTruthy();
    });
  });

  test.describe('Client User', () => {
    test('should not have access to API logs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/settings/api/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('settings/api/logs')).toBeTruthy();
    });
  });

  test.describe('Viewer User', () => {
    test('should not have access to API logs', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/settings/api/logs');
      const bodyText = await page.locator('body').textContent();
      const hasError = bodyText.toLowerCase().includes('error') ||
                       bodyText.toLowerCase().includes('denied') ||
                       page.url().endsWith('/') ||
                       page.url().includes('/?');
      expect(hasError || !page.url().includes('settings/api/logs')).toBeTruthy();
    });
  });
});

// Cleanup: remove test API keys created during this test run
test.afterAll(async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();

  await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

  const testKeyNames = ['test-api-logs-key'];

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
