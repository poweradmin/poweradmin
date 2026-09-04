import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// The producer and its member are built once and reused across the assertions.
test.describe.configure({ mode: 'serial' });

test.describe('Catalog zone members', () => {
  const stamp = Date.now();
  const producerZone = `producer-${stamp}.example.com`;
  const memberZone = `member-${stamp}.example.com`;
  const nativeZone = `native-${stamp}.example.com`;

  async function addZone(page, name, kind) {
    await page.goto('/zones/add/master');
    await page.waitForLoadState('networkidle');
    await page.locator('[data-testid="zone-name-input"]').fill(name);
    await page.locator('[data-testid="zone-type-select"]').selectOption(kind);
    await page.locator('[data-testid="add-zone-button"]').click();
    await page.waitForLoadState('networkidle');
  }

  async function openZone(page, name) {
    await page.goto('/zones/forward?letter=all&rows_per_page=100');
    await page.waitForLoadState('networkidle');
    await page.locator(`tr:has-text("${name}") a[href*="/edit"]`).first().click();
    await page.waitForLoadState('networkidle');
  }

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('creates the zones the rest of the spec needs', async ({ page }) => {
    await addZone(page, producerZone, 'PRODUCER');
    await addZone(page, memberZone, 'MASTER');
    await addZone(page, nativeZone, 'NATIVE');

    await page.goto('/zones/forward?letter=all&rows_per_page=100');
    await page.waitForLoadState('networkidle');

    for (const name of [producerZone, memberZone, nativeZone]) {
      await expect(page.locator(`tr:has-text("${name}")`)).toHaveCount(1);
    }
  });

  test('offers the catalog page on a producer and not on a primary', async ({ page }) => {
    await openZone(page, producerZone);
    await expect(page.locator('[data-testid="catalog-members-button"]')).toHaveCount(1);

    await openZone(page, memberZone);
    await expect(page.locator('[data-testid="catalog-members-button"]')).toHaveCount(0);
  });

  test('adds a member from the producer page and refuses kinds PowerDNS will not publish', async ({ page }) => {
    await openZone(page, producerZone);
    await page.locator('[data-testid="catalog-members-button"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/zones\/\d+\/catalog/);

    // A NATIVE zone accepts a catalog value and is then never served, so it must
    // not be offered in the first place.
    const options = await page.locator('[data-testid="catalog-member-select"] option').evaluateAll(
      opts => opts.map(o => o.textContent.trim())
    );
    expect(options).toContain(memberZone);
    expect(options).not.toContain(nativeZone);

    await page.locator('[data-testid="catalog-member-select"]').selectOption({ label: memberZone });
    await page.locator('[data-testid="catalog-add-member"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator(`li:has-text("${memberZone}")`)).toHaveCount(1);
  });

  test('shows the assignment on the member zone', async ({ page }) => {
    await openZone(page, memberZone);

    await expect(page.locator('[data-testid="zone-catalog-select"]')).toHaveValue(/\d+/);
    const selected = await page.locator('[data-testid="zone-catalog-select"] option:checked').textContent();
    expect(selected.trim()).toBe(producerZone);
  });

  test('clears the catalog from the member zone', async ({ page }) => {
    await openZone(page, memberZone);

    await page.locator('[data-testid="zone-catalog-select"]').selectOption('');
    await page.locator('button[name="catalog_change"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('[data-testid="zone-catalog-select"]')).toHaveValue('');

    await openZone(page, producerZone);
    await page.locator('[data-testid="catalog-members-button"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator(`li:has-text("${memberZone}")`)).toHaveCount(0);
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    for (const name of [memberZone, nativeZone, producerZone]) {
      await page.goto('/zones/forward?letter=all&rows_per_page=100');
      const link = page.locator(`tr:has-text("${name}") a[href*="/delete"]`);
      if (await link.count() > 0) {
        await link.first().click();
        await page.locator('button[type="submit"]').first().click().catch(() => {});
        await page.waitForLoadState('domcontentloaded');
      }
    }

    await page.close();
  });
});
