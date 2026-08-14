import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Creation and the follow-up assertions run against the same zone.
test.describe.configure({ mode: 'serial' });

test.describe('Catalog consumer zones', () => {
  const consumerZone = `consumer-${Date.now()}.example.com`;
  const primaryIp = '192.0.2.42';
  const API_KEY = 'test-api-key-for-automated-testing-12345';

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('offers CONSUMER and swaps the form to primaries when it is selected', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    const typeSelect = page.locator('[data-testid="zone-type-select"]');
    await expect(typeSelect).toBeVisible();

    const kinds = await typeSelect.locator('option').evaluateAll(options => options.map(o => o.value));
    expect(kinds).toContain('CONSUMER');

    const mastersInput = page.locator('[data-testid="zone-master-input"]');
    const localFields = page.locator('#local_zone_fields');

    // A locally served kind hides the primaries field and shows the local settings.
    await typeSelect.selectOption('MASTER');
    await expect(mastersInput).toBeHidden();
    await expect(localFields).toBeVisible();

    await typeSelect.selectOption('CONSUMER');
    await expect(mastersInput).toBeVisible();
    await expect(mastersInput).toHaveAttribute('required', '');
    await expect(localFields).toBeHidden();
  });

  test('rejects a consumer zone submitted without a primary', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(`no-primary-${Date.now()}.example.com`);
    await page.locator('[data-testid="zone-type-select"]').selectOption('CONSUMER');

    // Drop the required attribute so the POST reaches the server-side guard.
    await page.locator('[data-testid="zone-master-input"]').evaluate(el => el.removeAttribute('required'));
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/zones\/add\/master/);
    await expect(page.locator('body')).toContainText(/not a valid IPv4 or IPv6 address/i);
  });

  test('creates a consumer zone with its primary and no seeded records', async ({ page }) => {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-testid="zone-name-input"]').fill(consumerZone);
    await page.locator('[data-testid="zone-type-select"]').selectOption('CONSUMER');
    await page.locator('[data-testid="zone-master-input"]').fill(primaryIp);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');

    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/fatal|exception/i);

    await page.goto('/zones/forward?letter=all&rows_per_page=100');
    await page.waitForLoadState('networkidle');

    const zoneRow = page.locator(`tr:has-text("${consumerZone}")`);
    await expect(zoneRow).toHaveCount(1);
    await expect(zoneRow).toContainText(/consumer/i);
  });

  test('shows the primary, stays read-only, and refuses a silent retype', async ({ page, request }) => {
    await page.goto('/zones/forward?letter=all&rows_per_page=100');
    await page.waitForLoadState('networkidle');

    await page.locator(`tr:has-text("${consumerZone}") a[href*="/edit"]`).first().click();
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/zones\/\d+\/edit/);

    // Read the record set over the API rather than off the page: a read-only zone
    // renders records as text, so counting inputs would pass no matter what.
    const zoneId = page.url().match(/zones\/(\d+)\/edit/)[1];
    const response = await request.get(`/api/v2/zones/${zoneId}/records`, {
      headers: { 'X-API-Key': API_KEY },
    });
    expect(response.ok()).toBeTruthy();

    // The consumer takes its catalog by transfer, so nothing was seeded locally.
    const { data } = await response.json();
    expect(data.records).toHaveLength(0);

    // The primary is shown and editable.
    const masterInput = page.locator('input[name="new_master"]');
    await expect(masterInput).toBeVisible();
    await expect(masterInput).toHaveValue(primaryIp);

    // getTypes() has no CONSUMER option, so the form must not render at all -
    // otherwise the browser preselects MASTER and one click retypes the zone.
    await expect(page.locator('select[name="newtype"]')).toHaveCount(0);
    await expect(page.locator('button[name="type_change"]')).toHaveCount(0);
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    await page.goto('/zones/forward?letter=all&rows_per_page=100');
    const row = page.locator(`tr:has-text("${consumerZone}") a[href*="/delete"]`);
    if (await row.count() > 0) {
      await row.first().click();
      await page.locator('button[type="submit"]').first().click().catch(() => {});
    }
    await page.close();
  });
});
