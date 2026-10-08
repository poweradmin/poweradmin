import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('Record save busy state - Issue #1409', () => {
  // The busy state only exists while the POST is in flight, so the response is
  // held open deliberately rather than racing a real save.
  test('shows a spinner and blocks repeat submits while the add POST is pending', async ({ page, workerZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = workerZone.id;

    await page.goto(`/zones/${zoneId}/records/add`);

    await page.locator('select[name*="type"]').first().selectOption('A');
    await page.locator('input[name*="name"]').first().fill(`busy-${Date.now()}`);
    await page.locator('input[name*="content"]').first().fill('192.0.2.44');

    let postCount = 0;
    await page.route('**/records/add', async route => {
      if (route.request().method() === 'POST') {
        postCount++;
      }
      await route.continue();
    });

    // Record the button state at submit time and stash it somewhere that survives
    // the navigation. Querying the DOM while the POST is in flight only blocks on
    // "waiting for navigation to finish". The second requestSubmit proves the form
    // refuses a repeat while it is already submitting.
    await page.evaluate(() => {
      document.addEventListener('submit', () => {
        const b = document.querySelector('button[name="commit"]');
        sessionStorage.setItem('addBusyProbe', JSON.stringify({
          ariaBusy: b.getAttribute('aria-busy'),
          spinner: !!b.querySelector('.spinner-border'),
        }));
        b.form.requestSubmit(b);
      }, { once: true });
    });

    await page.locator('button[name="commit"]').click();
    await page.waitForLoadState('domcontentloaded');

    const probe = JSON.parse(await page.evaluate(() => sessionStorage.getItem('addBusyProbe')));
    expect(probe.ariaBusy).toBe('true');
    expect(probe.spinner).toBe(true);
    expect(postCount).toBe(1);

    await page.unroute('**/records/add');
  });

  test('leaves the button usable when validation rejects the submit', async ({ page, workerZone }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = workerZone.id;

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
  test('shows a spinner on the shared delete confirmation button', async ({ page, tempZone }) => {
    test.slow(); // creates throwaway zones on top of the test itself
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = tempZone.id;

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

    // The delete was submitted; wait for it to finish before the fixture cleans up
    await expect(page).not.toHaveURL(/\/delete$/);
  });
});
