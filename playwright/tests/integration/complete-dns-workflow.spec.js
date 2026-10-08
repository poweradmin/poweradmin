import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { createZone, deleteZoneById, findZoneIdByName, uniqueName, zoneExists } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// Run tests serially as they depend on each other
test.describe.configure({ mode: 'serial' });

test.describe('Complete DNS Management Workflow Integration', () => {
  const companyName = uniqueName('flow');
  const primaryDomain = `${companyName}.example.com`;
  let zoneId = null;

  // Add one record through the form at the top of the zone edit page
  async function addRecord(page, { type, name, content, prio }) {
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('domcontentloaded');

    await page.locator('#recordTypeSelectTop').selectOption(type);
    await page.locator('input[name="name"]').first().fill(name);
    await page.locator('#recordContentTop').fill(content);
    if (prio !== undefined) {
      await page.locator('#priorityFieldTop').fill(prio);
    }
    await page.getByRole('button', { name: 'Add record' }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  }

  async function storedContents(page) {
    await page.goto(`/zones/${zoneId}/edit`);
    await page.waitForLoadState('domcontentloaded');
    return page.locator('[name^="record["][name$="[content]"]').evaluateAll(els => els.map(e => e.value));
  }

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const id = zoneId ?? await findZoneIdByName(page, primaryDomain);
    if (id) {
      await deleteZoneById(page, id);
    }
    await page.close();
  });

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should complete full company DNS setup workflow', async ({ page }) => {
    // Six record adds and an edit page reload are many page loads under load
    test.slow();

    // Step 1: Create primary company domain
    zoneId = await createZone(page, primaryDomain);
    expect(zoneId, `zone ${primaryDomain} must be created`).toBeTruthy();

    // Step 2: Add essential DNS records for company infrastructure
    await addRecord(page, { type: 'A', name: 'www', content: '192.168.1.10' });
    await addRecord(page, { type: 'A', name: '@', content: '192.168.1.10' });
    await addRecord(page, { type: 'A', name: 'mail', content: '192.168.1.20' });
    await addRecord(page, { type: 'MX', name: '@', content: `mail.${primaryDomain}.`, prio: '10' });
    await addRecord(page, { type: 'CNAME', name: 'ftp', content: `${primaryDomain}.` });
    await addRecord(page, { type: 'TXT', name: '@', content: '"v=spf1 mx a ip4:192.168.1.20 -all"' });

    const contents = await storedContents(page);
    expect(contents).toContain('192.168.1.10');
    expect(contents).toContain('192.168.1.20');
    // The app strips the trailing dot from hostnames before storing them
    expect(contents).toContain(`mail.${primaryDomain}`);
    expect(contents).toContain(primaryDomain);
    expect(contents).toContain('"v=spf1 mx a ip4:192.168.1.20 -all"');
  });

  test('should validate complete DNS infrastructure', async ({ page }) => {
    expect(await findZoneIdByName(page, primaryDomain), `zone ${primaryDomain} must exist`).toBe(zoneId);

    // Every record type added by the previous test is listed
    await page.goto(`/zones/${zoneId}/edit`);
    const types = await page.locator('[name^="record["][name$="[type]"]').evaluateAll(els => els.map(e => e.value));
    for (const type of ['SOA', 'A', 'MX', 'CNAME', 'TXT']) {
      expect(types, `${type} record must be listed`).toContain(type);
    }
    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
  });

  test('should clean up test domains', async ({ page }) => {
    expect(zoneId, 'the workflow zone must have been created').toBeTruthy();
    expect(await deleteZoneById(page, zoneId), 'delete confirmation must be offered').toBe(true);

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    expect(await zoneExists(page, primaryDomain)).toBe(false);
  });
});
