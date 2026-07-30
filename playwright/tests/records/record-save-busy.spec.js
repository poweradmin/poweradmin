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

  // The confirm button comes from the shared delete_actions macro, so this
  // covers every delete-confirmation page at once.
  test('shows a spinner on the shared delete confirmation button', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    const zoneName = `busy-delete-${Date.now()}.example.com`;
    await page.goto('/zones/add/master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(zoneName);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/forward?letter=all');
    const row = page.locator('tr', { hasText: zoneName }).first();
    const href = await row.locator('a[href*="/edit"]').first().getAttribute('href');
    const zoneId = href && href.match(/\/zones\/(\d+)\/edit/)?.[1];
    expect(zoneId).toBeTruthy();

    await page.goto(`/zones/${zoneId}/delete`);

    // Record the button state at submit time and stash it somewhere that
    // survives the navigation, rather than racing the page unload.
    await page.evaluate(() => {
      document.addEventListener('submit', () => {
        const b = document.querySelector('[data-testid="confirm-delete-zone"]');
        sessionStorage.setItem('busyProbe', JSON.stringify({
          ariaBusy: b.getAttribute('aria-busy'),
          spinner: !!b.querySelector('.spinner-border'),
          label: b.textContent.trim(),
        }));
      });
    });

    await page.locator('[data-testid="confirm-delete-zone"]').click();
    await page.waitForLoadState('domcontentloaded');

    const probe = JSON.parse(await page.evaluate(() => sessionStorage.getItem('busyProbe')));
    expect(probe.ariaBusy).toBe('true');
    expect(probe.spinner).toBe(true);
    expect(probe.label).toBe('Saving...');
  });
});
