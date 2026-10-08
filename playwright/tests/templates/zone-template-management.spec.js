import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addTemplateRecord, createTemplate, deleteTemplate } from '../../helpers/templates.js';
import { deleteZoneById, findZoneIdByName, uniqueName } from '../../helpers/zones.js';

test.describe('Zone Template Management', () => {
  const templateName = uniqueName('ztm');

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should list zone templates', async ({ page }) => {
    await page.goto('/zones/templates');
    await expect(page).toHaveURL(/.*zones\/templates/);

    // Page should show templates table or empty state
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/template|add|Zone Templates/i);
  });

  test('should add a new zone template', async ({ page }) => {
    await page.goto('/zones/templates/add');
    await page.waitForLoadState('networkidle');

    const nameInput = page.locator('[data-testid="zone-templ-name-input"], input[name*="name"], input[name*="templ"]').first();
    await nameInput.fill(templateName);
    await page.locator('[data-testid="zone-templ-desc-input"], textarea[name*="desc"], input[name*="desc"]').first()
      .fill('Template created by Playwright tests');

    const submitBtn = page.locator('[data-testid="add-zone-templ-button"], button[type="submit"], input[type="submit"]').first();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');

    await page.goto('/zones/templates');
    const row = page.locator('.template-row').filter({ hasText: templateName });
    try {
      await expect(row, `template ${templateName} must be listed`).toHaveCount(1);
    } finally {
      const href = await row.locator('a[href$="/edit"]').first().getAttribute('href').catch(() => null);
      const id = href?.match(/templates\/(\d+)\/edit/)?.[1];
      if (id) {
        await deleteTemplate(page, id);
      }
    }
  });

  test('should add records to a zone template', async ({ page }) => {
    // Own template so the test does not depend on another test having run first
    const ownName = `${templateName}-records`;
    const templateId = await createTemplate(page, ownName);
    try {
      expect(templateId).toBeTruthy();

      await addTemplateRecord(page, templateId, { type: 'A', name: 'www', content: '192.168.1.10' });

      await page.goto(`/zones/templates/${templateId}/edit`);
      const recordRow = page.locator('table tbody tr').filter({ hasText: 'www' }).first();
      await expect(recordRow).toContainText('192.168.1.10');
    } finally {
      await deleteTemplate(page, templateId);
    }
  });

  test('should apply a zone template when creating a zone', async ({ page }) => {
    test.slow();
    const ownName = `${templateName}-apply`;
    const zoneName = `${ownName}.example.com`;
    const templateId = await createTemplate(page, ownName);
    let zoneId = null;
    try {
      expect(templateId, `template ${ownName} must be created`).toBeTruthy();
      await addTemplateRecord(page, templateId, { type: 'A', name: 'www', content: '192.168.1.11' });

      await page.goto('/zones/add/master');
      await page.locator('#domain').fill(zoneName);
      await page.locator('#zone_template').selectOption(templateId);
      await page.locator('button[type="submit"]').first().click();
      await expect(page.locator('body')).toContainText(/success|added|created/i);

      zoneId = await findZoneIdByName(page, zoneName);
      expect(zoneId, `zone ${zoneName} must be created`).toBeTruthy();
    } finally {
      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }
      if (templateId) {
        await deleteTemplate(page, templateId);
      }
    }
  });

  test('should edit a zone template', async ({ page }) => {
    const ownName = `${templateName}-edit`;
    const templateId = await createTemplate(page, ownName);
    try {
      expect(templateId, `template ${ownName} must be created`).toBeTruthy();

      await page.goto('/zones/templates');
      const templateRow = page.locator('table tbody tr').filter({ hasText: ownName });
      await expect(templateRow).toHaveCount(1);
      await templateRow.locator('a[href$="/edit"]').click();

      await expect(page).toHaveURL(new RegExp(`/zones/templates/${templateId}/edit`));
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('input[name="templ_name"]')).toHaveValue(ownName);
    } finally {
      if (templateId) {
        await deleteTemplate(page, templateId);
      }
    }
  });

  test('should delete a zone template', async ({ page }) => {
    // Own template so the test does not depend on another test having run first
    const ownName = `${templateName}-delete`;
    const templateId = await createTemplate(page, ownName);
    expect(templateId, `template ${ownName} must be created`).toBeTruthy();

    await page.goto('/zones/templates');
    const templateRow = page.locator('table tbody tr').filter({ hasText: ownName });
    await expect(templateRow).toHaveCount(1);
    await templateRow.locator('a[href$="/delete"]').click();

    await page.locator('button[name="confirm"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    await page.goto('/zones/templates');
    await expect(page.locator('table tbody tr').filter({ hasText: ownName })).toHaveCount(0);
  });
});
