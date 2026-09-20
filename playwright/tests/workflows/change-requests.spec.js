import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { getTestZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// One record in the shared zone travels through file -> approve -> reject ->
// cancel, and every step reads the state the previous one left behind.
test.describe.configure({ mode: 'serial' });

test.describe('Change approval workflow', () => {
  const recordName = `cr-e2e-${Date.now()}`;
  let zoneId = null;
  let requestId = null;

  async function recordTtlInput(page) {
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');
    const nameInput = page.locator(`input[value^="${recordName}."]`).first();
    await expect(nameInput).toBeVisible();
    const recordId = (await nameInput.getAttribute('name')).match(/record\[([^\]]+)\]/)[1];
    return page.locator(`input[name="record[${recordId}][ttl]"]`);
  }

  async function submitRequest(page, ttl, reason) {
    const ttlInput = await recordTtlInput(page);
    await ttlInput.fill(String(ttl));
    await page.getByTestId('request-comment-input').fill(reason);
    await page.getByTestId('save-changes-button').click();
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('system-message').filter({ hasText: 'submitted for approval' })).toBeVisible();
    const card = page.getByTestId('pending-change-requests-card');
    await expect(card).toBeVisible();
    await expect(card).toContainText(users.requester.username);
    const link = card.locator('a[data-testid^="change-request-link-"]').first();
    const href = await link.getAttribute('href');
    const match = href.match(/\/zones\/requests\/(\d+)/);
    expect(match, 'pending card should link to the review page').not.toBeNull();
    return match[1];
  }

  test('admin seeds a record in the shared zone', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    zoneId = await getTestZoneId(page, 'shared');
    expect(zoneId, 'shared-zone.example.com should exist').not.toBeNull();

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');
    await page.locator('input[name="name"]').first().fill(recordName);
    await page.locator('input[name="content"]').first().fill('203.0.113.42');
    await page.locator('input[name="ttl"]').first().fill('3600');
    await page.getByRole('button', { name: 'Add record' }).click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator(`input[value^="${recordName}."]`).first()).toBeVisible();
  });

  test('requester sees the request-mode editor and files a TTL change', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.requester.username, users.requester.password);

    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByTestId('save-changes-button')).toHaveText(/Submit for approval/);
    await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Bulk add' })).toHaveCount(0);

    requestId = await submitRequest(page, 7200, 'raise the ttl');

    // Nothing was written: the zone still serves the old TTL
    await expect(await recordTtlInput(page)).toHaveValue('3600');
  });

  test('admin sees the pending count in the navigation', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward');
    await page.waitForLoadState('networkidle');

    // The badge sits inside the Zones menu: a dropdown in the default theme, a
    // sidebar section (already open on a zones page) in the modern theme
    const badge = page.getByTestId('pending-change-requests-badge');
    const dropdownToggle = page.locator('a.dropdown-toggle[role="button"]', { hasText: 'Zones' });
    if (await dropdownToggle.count() > 0) {
      await dropdownToggle.first().click();
    }
    await expect(badge).toBeVisible();
    expect(Number(await badge.textContent())).toBeGreaterThanOrEqual(1);
  });

  test('manager approves the request and the record changes', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);

    await page.goto('/zones/requests');
    await page.waitForLoadState('networkidle');
    const row = page.getByTestId(`change-request-row-${requestId}`);
    await expect(row).toBeVisible();
    await expect(row).toContainText(users.requester.username);
    await row.getByTestId(`change-request-link-${requestId}`).click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(new RegExp(`/zones/requests/${requestId}$`));
    await expect(page.getByTestId('change-request-status')).toHaveText('pending');
    // The TTL is the changed field, so both halves of the edit row show it in bold
    const actions = page.getByTestId('change-request-actions');
    await expect(actions.locator('tr.table-danger td.fw-bold', { hasText: '3600' })).toBeVisible();
    await expect(actions.locator('tr.table-success td.fw-bold', { hasText: '7200' })).toBeVisible();

    await page.getByTestId('review-comment-input').fill('looks fine');
    await page.getByTestId('approve-change-request').click();
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('system-message').filter({ hasText: 'approved and applied' })).toBeVisible();
    await expect(page.getByTestId('change-request-status')).toHaveText('approved');
    await expect(page.getByTestId('approve-change-request')).toHaveCount(0);

    await expect(await recordTtlInput(page)).toHaveValue('7200');
  });

  test('a rejected request leaves the record unchanged', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.requester.username, users.requester.password);
    requestId = await submitRequest(page, 600, 'lower the ttl');

    await page.context().clearCookies();
    await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
    await page.goto(`/zones/requests/${requestId}`);
    await page.waitForLoadState('networkidle');
    await page.getByTestId('review-comment-input').fill('too low');
    await page.getByTestId('reject-change-request').click();
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('system-message').filter({ hasText: 'rejected' })).toBeVisible();
    await expect(page.getByTestId('change-request-status')).toHaveText('rejected');
    await expect(page.getByTestId('change-request-card')).toContainText('too low');

    await expect(await recordTtlInput(page)).toHaveValue('7200');
  });

  test('the requester can cancel their own request', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.requester.username, users.requester.password);
    requestId = await submitRequest(page, 900, 'changed my mind');

    await page.goto(`/zones/requests/${requestId}`);
    await page.waitForLoadState('networkidle');
    // A requester without the approve permission gets no review buttons
    await expect(page.getByTestId('approve-change-request')).toHaveCount(0);
    await page.getByTestId('cancel-change-request').click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/\/zones\/requests$/);
    await expect(page.getByTestId('system-message').filter({ hasText: 'cancelled' })).toBeVisible();

    await page.goto(`/zones/requests?status=cancelled`);
    await page.waitForLoadState('networkidle');
    await expect(page.getByTestId(`change-request-row-${requestId}`)).toContainText('cancelled');

    await expect(await recordTtlInput(page)).toHaveValue('7200');
  });

  test('bulk add is refused for a requester', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.requester.username, users.requester.password);
    await page.goto(`/zones/${zoneId}/records/bulk`);
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('system-message')).toContainText('requires approval');
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto(`/zones/${zoneId}/edit`);
      await page.waitForLoadState('networkidle');
      const nameInput = page.locator(`input[value^="${recordName}."]`).first();
      if (await nameInput.count() > 0) {
        const recordId = (await nameInput.getAttribute('name')).match(/record\[([^\]]+)\]/)[1];
        await page.goto(`/zones/${zoneId}/records/${recordId}/delete`);
        await page.waitForLoadState('networkidle');
        await page.getByTestId('confirm-delete-record').click();
        await page.waitForLoadState('networkidle');
      }
    } catch {
      // Ignore cleanup errors
    }
    await page.close();
  });
});
