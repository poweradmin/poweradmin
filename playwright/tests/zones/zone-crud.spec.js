import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { createZone, deleteZoneByName, errorMessages, findZoneIdByName, uniqueZoneName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

/**
 * Submit an add-zone form with the required-field checks removed, so the server-side
 * validation is what refuses the post (the page script re-runs checkValidity on submit).
 */
async function submitWithoutClientValidation(page) {
  await page.locator('form:has(#domain) [required]').evaluateAll(fields => fields.forEach(f => f.removeAttribute('required')));
  await page.locator('button[name="submit"]').click();
}

/** The add form refused the post: still on the form, with a danger alert. */
async function expectAddRefused(page, path) {
  await expect(page).toHaveURL(new RegExp(path));
  await expect(page.locator('[data-testid="system-message"].alert-danger').first()).toBeVisible();
}

test.describe('Zone CRUD Operations', () => {
  const created = [];

  // Zones the form tests may have created; the delete tests remove their own
  function track(name) {
    created.push(name);
    return name;
  }

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    try {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      // One refused delete must not leave every later zone behind
      for (const name of created) {
        await deleteZoneByName(page, name).catch(error => console.warn(`cleanup of ${name} failed: ${error.message}`));
      }
    } finally {
      await page.close();
    }
  });

  test.describe('List Zones', () => {
    test('admin should see all zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      await expect(page).toHaveURL(/.*zones\/forward/);
      const table = page.locator('table').first();
      await expect(table).toBeVisible();
    });

    test('should display zone columns', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/name|zone|type|records/i);
    });

    test('should show zone type indicator', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/zones/forward?letter=all');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/master|slave|native/i);
    });

    test('manager should see zones', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/zones/forward?letter=all');

      await expect(page).toHaveURL(/.*zones\/forward/);
    });

    test('viewer should see zones in read-only mode', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      // Viewer should not see add buttons
      const addBtn = page.locator('a[href*="/zones/add/master"], a[href*="/zones/add/slave"]');
      expect(await addBtn.count()).toBe(0);
    });
  });

  test.describe('Add Master Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add master zone page', async ({ page }) => {
      await page.goto('/zones/add/master');
      await expect(page).toHaveURL(/.*zones\/add\/master/);
    });

    test('should display zone name field', async ({ page }) => {
      await page.goto('/zones/add/master');

      const nameField = page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should create master zone with valid domain', async ({ page }) => {
      // createZone throws unless the form redirects to the list and the zone is found by name
      const zoneId = await createZone(page, track(uniqueZoneName('crudm')));
      expect(zoneId).toMatch(/^\d+$/);
    });

    test('should reject empty domain name', async ({ page }) => {
      await page.goto('/zones/add/master');
      await expect(page.locator('#domain')).toHaveJSProperty('validity.valueMissing', true);

      await submitWithoutClientValidation(page);
      await expectAddRefused(page, 'zones/add/master');
    });

    test('should reject invalid domain format', async ({ page }) => {
      await page.goto('/zones/add/master');

      await page.locator('#domain').fill('invalid..domain');
      await page.locator('button[name="submit"]').click();

      await expectAddRefused(page, 'zones/add/master');
      expect(await findZoneIdByName(page, 'invalid..domain')).toBeNull();
    });

    test('should refuse a domain that already exists', async ({ page }) => {
      const name = track(uniqueZoneName('crud-dup'));
      const zoneId = await createZone(page, name);

      await page.goto('/zones/add/master');
      await page.locator('#domain').fill(name);
      await page.locator('button[name="submit"]').click();

      await expectAddRefused(page, 'zones/add/master');
      expect(await errorMessages(page)).toMatch(/already/i);
      // Still exactly the zone created above
      expect(await findZoneIdByName(page, name)).toBe(zoneId);
    });
  });

  test.describe('Add Slave Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add slave zone page', async ({ page }) => {
      await page.goto('/zones/add/slave');
      await expect(page).toHaveURL(/.*zones\/add\/slave/);
    });

    test('should display zone name field', async ({ page }) => {
      await page.goto('/zones/add/slave');

      const nameField = page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first();
      await expect(nameField).toBeVisible();
    });

    test('should display master IP field', async ({ page }) => {
      await page.goto('/zones/add/slave');

      const masterField = page.locator('input[name*="master"], input[name*="ip"]').first();
      await expect(masterField).toBeVisible();
    });

    /** Create a slave zone through the form and assert it exists with the given master. */
    async function expectSlaveCreated(page, name, master) {
      // createZone throws with the refusal reason, or when the zone is missing afterwards
      const zoneId = await createZone(page, name, 'slave', { master });
      await page.goto(`/zones/${zoneId}/edit`);
      await expect(page.locator('input[name="new_master"]')).toHaveValue(master);
    }

    test('should create slave zone with valid data', async ({ page }) => {
      await expectSlaveCreated(page, track(uniqueZoneName('crud-slave')), '192.168.1.1');
    });

    test('should reject empty master IP', async ({ page }) => {
      const name = track(uniqueZoneName('crud-nomaster'));
      await page.goto('/zones/add/slave');
      await page.locator('#domain').fill(name);
      await expect(page.locator('#slave_master')).toHaveJSProperty('validity.valueMissing', true);

      await submitWithoutClientValidation(page);
      await expectAddRefused(page, 'zones/add/slave');
      expect(await findZoneIdByName(page, name)).toBeNull();
    });

    test('should accept IPv6 master address', async ({ page }) => {
      await expectSlaveCreated(page, track(uniqueZoneName('crud-ipv6')), '2001:db8::1');
    });
  });

  test.describe('Delete Zone', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display delete confirmation message', async ({ page }) => {
      // Own zone: the first delete link on the list belongs to whichever spec got there first
      const zoneId = await createZone(page, track(uniqueZoneName('crud-dconf')));
      await page.goto(`/zones/${zoneId}/delete`);

      await expect(page.locator('body')).toContainText(/delete|confirm|sure/i);

      await page.locator('button[type="submit"]').first().click();
    });

    test('should cancel delete and return to previous page', async ({ page }) => {
      const zoneName = track(uniqueZoneName('crud-dcancel'));
      const zoneId = await createZone(page, zoneName);
      await page.goto(`/zones/${zoneId}/delete`);

      await page.locator('[data-testid="cancel-delete-zone"]').click();
      await expect(page).toHaveURL(/zones\/forward/);

      // Cancelling must leave the zone alone
      await page.goto(`/zones/${zoneId}/delete`);
      await expect(page.locator('body')).toContainText(zoneName);
      await page.locator('button[type="submit"]').first().click();
    });
  });

  test.describe('Permission Tests', () => {
    test('client should not have add zone buttons in main content', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.client.username, users.client.password);
      await page.goto('/zones/forward?letter=all');

      // Check only main content area, excluding navigation menus
      // Client may or may not have zone add permissions depending on configuration
      const mainContent = page.locator('main, .content, #content, .container-fluid > .row').first();
      const addMasterBtn = mainContent.locator('a[href*="/zones/add/master"]');
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      expect(await addMasterBtn.count()).toBe(0);
    });

    test('viewer should not have delete zone buttons', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/zones/forward?letter=all');

      const deleteBtn = page.locator('a[href*="/delete"]');
      expect(await deleteBtn.count()).toBe(0);
    });
  });
});
