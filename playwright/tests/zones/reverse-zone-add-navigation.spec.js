import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Regression: issue #1225 - reverse zones list "Add master/slave zone"
// previously routed to the forward-zone creation flow.
test.describe.configure({ mode: 'serial' });

test.describe('Reverse zones list add-zone navigation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('Add master zone link from reverse list preserves reverse context', async ({ page }) => {
    await page.goto('/zones/reverse');
    await page.waitForLoadState('networkidle');

    const addMaster = page.locator('a.btn-primary[href*="/zones/add/master"]').first();
    await expect(addMaster).toHaveAttribute('href', /type=reverse/);

    await addMaster.click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/\/zones\/add\/master\?type=reverse/);

    const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
    await expect(breadcrumb).toContainText('Reverse Zones');
    await expect(breadcrumb).not.toContainText('Forward Zones');

    // Form action and cancel link must keep reverse context across re-renders
    const form = page.locator('form.needs-validation');
    await expect(form).toHaveAttribute('action', /type=reverse/);
    await expect(page.locator('input[type="hidden"][name="type"][value="reverse"]')).toHaveCount(1);
    await expect(page.locator('a.btn-secondary[href$="/zones/reverse"]')).toHaveCount(1);
  });

  test('Add slave zone link from reverse list preserves reverse context', async ({ page }) => {
    await page.goto('/zones/reverse');
    await page.waitForLoadState('networkidle');

    const addSlave = page.locator('a.btn-secondary[href*="/zones/add/slave"]').first();
    await expect(addSlave).toHaveAttribute('href', /type=reverse/);

    await addSlave.click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/\/zones\/add\/slave\?type=reverse/);

    const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
    await expect(breadcrumb).toContainText('Reverse Zones');
    await expect(breadcrumb).not.toContainText('Forward Zones');

    const form = page.locator('form.needs-validation');
    await expect(form).toHaveAttribute('action', /type=reverse/);
    await expect(page.locator('input[type="hidden"][name="type"][value="reverse"]')).toHaveCount(1);
    await expect(page.locator('a.btn-secondary[href$="/zones/reverse"]')).toHaveCount(1);
  });

  test('Forward list still routes to forward add-zone flow', async ({ page }) => {
    await page.goto('/zones/forward');
    await page.waitForLoadState('networkidle');

    const addMaster = page.locator('a.btn-primary[href*="/zones/add/master"]').first();
    await expect(addMaster).toHaveAttribute('href', '/zones/add/master');

    await addMaster.click();
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveURL(/\/zones\/add\/master$/);

    const breadcrumb = page.locator('nav[aria-label="breadcrumb"]');
    await expect(breadcrumb).toContainText('Forward Zones');

    await expect(page.locator('input[type="hidden"][name="type"]')).toHaveCount(0);
    await expect(page.locator('a.btn-secondary[href$="/zones/forward"]')).toHaveCount(1);
  });
});
