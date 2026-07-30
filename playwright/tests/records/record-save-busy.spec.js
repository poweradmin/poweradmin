import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Record save busy state - Issue #1409', () => {
  async function getTestZoneId(page) {
    await page.goto('/zones/forward?letter=all');
    const editLink = page.locator('a[href*="/edit"]').first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const match = href.match(/\/zones\/(\d+)\/edit/);
      return match ? match[1] : null;
    }
    return null;
  }

  // The busy state only exists while the POST is in flight, so the response is
  // held open deliberately rather than racing a real save.
  test('shows a spinner and blocks repeat submits while the add POST is pending', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/records/add`);

    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(`busy-${Date.now()}`);
    await page.locator('input[name*="content"]').first().fill('192.0.2.44');

    let release;
    const held = new Promise(resolve => {
      release = resolve;
    });
    let postCount = 0;

    await page.route('**/records/add', async route => {
      if (route.request().method() !== 'POST') {
        await route.continue();
        return;
      }
      postCount++;
      await held;
      await route.continue();
    });

    const button = page.locator('button[name="commit"]');
    await button.click();

    await expect(button).toHaveAttribute('aria-busy', 'true');
    await expect(button.locator('.spinner-border')).toBeVisible();

    // A second click must not produce a second POST
    await button.click({ force: true });
    expect(postCount).toBe(1);

    release();
    await page.waitForLoadState('domcontentloaded');
    await page.unroute('**/records/add');
  });

  test('leaves the button usable when validation rejects the submit', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) return;

    await page.goto(`/zones/${zoneId}/records/add`);

    // Submitting the empty form trips client-side validation, which must not
    // leave the button stuck in its busy state.
    const button = page.locator('button[name="commit"]');
    await button.click();

    await expect(button).not.toHaveAttribute('aria-busy', 'true');
    await expect(button.locator('.spinner-border')).toHaveCount(0);
  });
});
