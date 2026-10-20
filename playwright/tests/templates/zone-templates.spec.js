import { test, expect, users } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addTemplateRecord, createTemplate, deleteTemplate } from '../../helpers/templates.js';
import { deleteZoneById, findZoneIdByName, uniqueName } from '../../helpers/zones.js';

async function createZoneWithTemplate(page, domain, templateId) {
  await page.goto('/zones/add/master');
  await page.locator('#domain').fill(domain);
  await page.locator('#zone_template').selectOption(templateId);
  await page.locator('button[type="submit"]').first().click();
  await expect(page.locator('body')).toContainText(/success|added|created/i);
  const zoneId = await findZoneIdByName(page, domain);
  expect(zoneId, `zone ${domain} must be created`).toBeTruthy();
  return zoneId;
}

test.describe('Zone Templates Management', () => {
  const templateName = uniqueName('ztpl');

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access zone templates page', async ({ page }) => {
    await page.goto('/zones/templates');
    await expect(page).toHaveURL(/.*zones\/templates/);
    // Page uses card-header with strong element instead of h1-3
    await expect(page.locator('.card-header strong, .card-header, .breadcrumb').first()).toBeVisible();
  });

  test('should display zone templates list or empty state', async ({ page }) => {
    await page.goto('/zones/templates');

    // Seeded templates (Standard Web Zone, Minimal Zone, ...) keep the table rendered
    await expect(page.locator('table').first()).toBeVisible();
    await expect(page.locator('.template-row').first()).toBeVisible();
  });

  test('should create a new zone template', async ({ page }) => {
    await page.goto('/zones/templates/add');
    await expect(page).toHaveURL(/.*zones\/templates\/add/);

    await expect(page.locator('form').first()).toBeVisible();

    await page.locator('input[name*="name"], input[name*="template"]').first().fill(templateName);
    await page.locator('input[name*="descr"], textarea[name*="descr"]').first().fill('Test template for Playwright testing');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    await expect(page.locator('body')).toContainText(/success|created|added/i);

    await page.goto('/zones/templates');
    const editHref = await page.locator('.template-row').filter({ hasText: templateName }).locator('a[href$="/edit"]').getAttribute('href');
    const templateId = editHref?.match(/templates\/(\d+)\/edit/)?.[1];
    try {
      expect(templateId, `template ${templateName} must be listed`).toBeTruthy();
    } finally {
      if (templateId) {
        await deleteTemplate(page, templateId);
      }
    }
  });

  test('should add records to zone template', async ({ page }) => {
    const ownName = `${templateName}-records`;
    const templateId = await createTemplate(page, ownName);
    try {
      expect(templateId).toBeTruthy();

      await addTemplateRecord(page, templateId, { type: 'A', name: 'www', content: '192.0.2.21' });

      await page.goto(`/zones/templates/${templateId}/edit`);
      const recordRow = page.locator('table tbody tr').filter({ hasText: 'www' }).first();
      await expect(recordRow).toContainText('192.0.2.21');
      await expect(recordRow).toContainText('A');
    } finally {
      if (templateId) {
        await deleteTemplate(page, templateId);
      }
    }
  });

  test('should use template when creating new zone', async ({ page }) => {
    test.slow();
    const ownName = `${templateName}-use`;
    const ownDomain = `${ownName}.example.com`;
    const templateId = await createTemplate(page, ownName);
    let zoneId = null;
    try {
      expect(templateId, `template ${ownName} must be created`).toBeTruthy();
      zoneId = await createZoneWithTemplate(page, ownDomain, templateId);
    } finally {
      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }
      if (templateId) {
        await deleteTemplate(page, templateId);
      }
    }
  });

  test('should verify template records applied to new zone', async ({ page }) => {
    test.slow();
    const ownName = `${templateName}-applied`;
    const ownDomain = `${ownName}.example.com`;
    const templateId = await createTemplate(page, ownName);
    let zoneId = null;
    try {
      expect(templateId).toBeTruthy();

      await addTemplateRecord(page, templateId, { type: 'A', name: 'www', content: '192.0.2.22' });

      zoneId = await createZoneWithTemplate(page, ownDomain, templateId);

      await page.goto(`/zones/${zoneId}/edit`);
      // Record values live in input attributes, so textContent never sees them
      await expect(page.locator('input[value="192.0.2.22"]')).toHaveCount(1);
    } finally {
      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }
      if (templateId) {
        await deleteTemplate(page, templateId);
      }
    }
  });

  test('should edit existing zone template', async ({ page }) => {
    // Own template so the test does not depend on another test having run first
    const ownName = `${templateName}-edit`;
    const templateId = await createTemplate(page, ownName);
    try {
      expect(templateId, `template ${ownName} must be created`).toBeTruthy();

      await page.goto('/zones/templates');
      const row = page.locator('table tbody tr').filter({ hasText: ownName });
      await expect(row).toHaveCount(1);
      await row.locator('a[href$="/edit"]').click();

      await expect(page).toHaveURL(new RegExp(`/zones/templates/${templateId}/edit`));
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('input[name="templ_name"]')).toHaveValue(ownName);
    } finally {
      await deleteTemplate(page, templateId);
    }
  });

  test('should validate template form fields', async ({ page }) => {
    await page.goto('/zones/templates/add');

    // Try to submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    await expect(page).toHaveURL(/.*zones\/templates\/add/);
  });

  test('should show template usage statistics', async ({ page }) => {
    test.slow();
    const ownName = `${templateName}-usage`;
    const ownDomain = `${ownName}.example.com`;
    const templateId = await createTemplate(page, ownName);
    let zoneId = null;
    try {
      expect(templateId, `template ${ownName} must be created`).toBeTruthy();
      zoneId = await createZoneWithTemplate(page, ownDomain, templateId);

      await page.goto('/zones/templates');
      const row = page.locator('.template-row').filter({ hasText: ownName });
      await expect(row).toHaveCount(1);
      await expect(row.locator('.badge.bg-secondary').filter({ hasText: /^\s*1\s*$/ })).toHaveCount(1);
    } finally {
      if (zoneId) {
        await deleteZoneById(page, zoneId);
      }
      if (templateId) {
        await deleteTemplate(page, templateId);
      }
    }
  });

  test('should have add button in card header', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const addButton = page.locator('.card-header a[href*="templates/add"]');
    await expect(addButton).toBeVisible();
    await expect(addButton).toContainText(/Add zone template/i);
  });

  test('should have search input that filters templates', async ({ page }) => {
    await page.goto('/zones/templates');
    await page.waitForLoadState('networkidle');

    const searchInput = page.locator('#template-search');
    // Seeded templates guarantee rows to filter
    await expect(page.locator('.template-row').first()).toBeVisible();

    await expect(searchInput).toBeVisible();

    // Get initial row count
    const initialCount = await page.locator('.template-row').count();

    // Type a search term that likely won't match all rows
    await searchInput.fill('zzzznonexistent');

    // All rows should be hidden
    const visibleAfterSearch = await page.locator('.template-row:visible').count();
    expect(visibleAfterSearch).toBe(0);

    // Clear search
    await page.locator('#clear-template-search').click();

    // All rows should be visible again
    const visibleAfterClear = await page.locator('.template-row:visible').count();
    expect(visibleAfterClear).toBe(initialCount);
  });

  test('should show action buttons on single line', async ({ page }) => {
    const ownName = `${templateName}-buttons`;
    const templateId = await createTemplate(page, ownName);
    try {
      expect(templateId).toBeTruthy();

      await page.goto('/zones/templates');
      const actionCell = page.locator(`tr:has-text("${ownName}") .d-flex.flex-nowrap`);
      await expect(actionCell).toBeVisible();

      const cellBox = await actionCell.boundingBox();
      // All buttons should fit within a reasonable height (single line)
      expect(cellBox.height).toBeLessThan(50);
    } finally {
      await deleteTemplate(page, templateId);
    }
  });
});
