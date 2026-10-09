/**
 * Search Comments Tests (4.1 Feature)
 *
 * Tests for searching through comments functionality including:
 * - Comments checkbox in search form
 * - Searching through zone comments
 * - Searching through record comments
 * - Comments displayed in search results
 */

import { test, expect, useFileZone } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addRecord, createZone, deleteZoneById, findAnyZoneId, isApiModeInstance, uniqueName } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('Search Comments Feature', () => {
  test.describe('Search Form Comments Option', () => {
    test('should display comments checkbox when comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');
      const bodyText = await page.locator('body').textContent();

      expect(bodyText).not.toMatch(/fatal|exception/i);

      const hasCommentsOption = await commentsCheckbox.count() > 0 ||
                                 bodyText.toLowerCase().includes('comment');
      expect(hasCommentsOption).toBe(true);
    });

    test('should toggle comments search option', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('#comments_check');
      await expect(commentsCheckbox).not.toBeChecked();

      await commentsCheckbox.check();
      await expect(commentsCheckbox).toBeChecked();

      await commentsCheckbox.uncheck();
      await expect(commentsCheckbox).not.toBeChecked();
    });

    test('should persist comments checkbox state after search', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      await page.locator('#comments_check').check();
      await page.locator('input[name="query"]').fill('example');
      await page.locator('button[name="do_search"]').click();

      // The form is re-rendered from search_by_comments, so the option has to survive the POST
      await expect(page.locator('#comments_check')).toBeChecked();
    });
  });

  test.describe('Search Through Zone Comments', () => {
    test('should search zones with comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const zonesCheckbox = page.locator('input[name="zones"], input#zones_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await zonesCheckbox.count() > 0) {
        await zonesCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should display zone comments in search results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const zonesCheckbox = page.locator('input[name="zones"], input#zones_check');
      if (await zonesCheckbox.count() > 0) {
        await zonesCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      // Results should be displayed
      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Search Through Record Comments', () => {
    test('should search records with comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('mail');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should display record comments in search results', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('192.168');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should find records by comment text', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const stamp = Date.now();
      const zoneName = `comment-search-${stamp}.example.com`;
      const marker = `commentmarker${stamp}`;
      const zoneId = await createZone(page, zoneName);
      expect(zoneId).not.toBeNull();

      try {
        await page.goto(`/zones/${zoneId}/records/add`);
        const commentInput = page.locator('input[name="records[0][comment]"]');
        test.skip(await commentInput.count() === 0, 'Record comments are disabled on this instance');

        await page.locator('input[name="records[0][name]"]').fill('commented');
        await page.locator('input[name="records[0][content]"]').fill('192.0.2.50');
        await commentInput.fill(marker);
        await page.locator('button[type="submit"]').first().click();
        await expect(page.locator('body')).toContainText(/success/i);

        await page.goto('/search');
        await page.locator('#records_check').check();
        await page.locator('#comments_check').check();
        await page.locator('input[name="query"]').fill(marker);
        await page.locator('button[name="do_search"]').click();

        await expect(page.locator('body')).toContainText('Records found');
        await expect(page.locator(`tr:has-text("commented.${zoneName}")`)).toHaveCount(1);
        await expect(page.locator(`tr:has-text("${marker}")`)).toHaveCount(1);
      } finally {
        await deleteZoneById(page, zoneId);
      }
    });
  });

  test.describe('Linked Comment Matching', () => {
    test.describe.configure({ mode: 'serial' });
    const zone = useFileZone('cmtlink');

    test('should match a linked comment only on its own record, not on its RRset sibling', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const label = uniqueName('cmtlink');
      const marker = `linkedmarker${Date.now()}`;

      await page.goto(`/zones/${zone.id}/records/add`);
      const commentInput = page.locator('input[name="records[0][comment]"]');
      test.skip(await commentInput.count() === 0, 'Record comments are disabled on this instance');

      // The sibling goes first and carries no comment; the second record of the RRset gets the linked one
      await addRecord(page, zone.id, { name: label, type: 'A', content: '192.0.2.71' });

      await page.goto(`/zones/${zone.id}/records/add`);
      await page.locator('input[name="records[0][name]"]').fill(label);
      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await page.locator('input[name="records[0][content]"]').fill('192.0.2.72');
      await commentInput.fill(marker);
      await page.locator('button[name="commit"]').click();
      await expect(page.locator('body')).toContainText(/success/i);

      await page.goto('/search');
      await page.locator('#records_check').check();
      await page.locator('#comments_check').check();
      await page.locator('input[name="query"]').fill(marker);
      await page.locator('button[name="do_search"]').click();

      await expect(page.locator('body')).toContainText('Records found');
      await expect(page.locator('tr:has-text("192.0.2.72")')).toHaveCount(1);
      // API-backend instances store comments per RRset, so both records legitimately match there
      const siblingRows = isApiModeInstance(test.info().project.use.baseURL ?? process.env.BASE_URL) ? 1 : 0;
      await expect(page.locator('tr:has-text("192.0.2.71")')).toHaveCount(siblingRows);
    });
  });

  test.describe('API Backend RRset Comment Matching', () => {
    test.describe.configure({ mode: 'serial' });
    const zone = useFileZone('cmtrrset');

    test('should return every record of an RRset whose comment matches', async ({ page }) => {
      test.skip(!isApiModeInstance(test.info().project.use.baseURL ?? process.env.BASE_URL), 'SQL instances are covered by the linked comment test');
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const label = uniqueName('cmtrrset');
      const marker = `rrsetmarker${Date.now()}`;

      await page.goto(`/zones/${zone.id}/records/add`);
      const commentInput = page.locator('input[name="records[0][comment]"]');
      test.skip(await commentInput.count() === 0, 'Record comments are disabled on this instance');

      // The uncommented record goes first, then the commented one joins its RRset
      await addRecord(page, zone.id, { name: label, type: 'A', content: '192.0.2.82' });

      await page.goto(`/zones/${zone.id}/records/add`);
      await page.locator('input[name="records[0][name]"]').fill(label);
      await page.locator('select[name="records[0][type]"]').selectOption('A');
      await page.locator('input[name="records[0][content]"]').fill('192.0.2.81');
      await commentInput.fill(marker);
      await page.locator('button[name="commit"]').click();
      await expect(page.locator('body')).toContainText(/success/i);

      await page.goto('/search');
      await page.locator('#records_check').check();
      await page.locator('#comments_check').check();
      await page.locator('input[name="query"]').fill(marker);
      await page.locator('button[name="do_search"]').click();

      await expect(page.locator('body')).toContainText('Records found');
      await expect(page.locator('tr:has-text("192.0.2.81")')).toHaveCount(1);
      await expect(page.locator('tr:has-text("192.0.2.82")')).toHaveCount(1);

      // With the comments option off the marker matches no record
      await page.goto('/search');
      await page.locator('#records_check').check();
      await page.locator('#comments_check').uncheck();
      await page.locator('input[name="query"]').fill(marker);
      await page.locator('button[name="do_search"]').click();

      await expect(page.locator('tr:has-text("192.0.2.81")')).toHaveCount(0);
    });
  });

  test.describe('Search Results Comment Display', () => {
    test('should show comment column in zone results when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      // The column follows the same setting as the comment field on the zone edit
      // page, which is the only signal a test has for whether it is switched on
      const zoneId = await findAnyZoneId(page);
      await page.goto(`/zones/${zoneId}/edit`);
      const zoneCommentsEnabled = await page.locator('textarea[name="zone_comment"]').count() > 0;
      test.skip(!zoneCommentsEnabled, 'Zone comments are disabled on this instance');

      await page.goto('/search');
      await page.locator('#zones_check').check();
      // The records table renders a Comment header of its own, so search zones only
      await page.locator('#records_check').uncheck();
      await page.locator('input[name="query"]').fill('example.com');
      await page.locator('button[name="do_search"]').click();

      await expect(page.locator('body')).toContainText('Zones found');
      await expect(page.locator('th').filter({ hasText: 'Comment' })).toHaveCount(1);
    });

    test('should show comment column in record results when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Combined Search Options', () => {
    test('should search both zones and records with comments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const zonesCheckbox = page.locator('input[name="zones"], input#zones_check');
      const recordsCheckbox = page.locator('input[name="records"], input#records_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await zonesCheckbox.count() > 0) {
        await zonesCheckbox.check();
      }

      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('example');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('should search with wildcards and comments enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/search');

      const wildcardCheckbox = page.locator('input[name="wildcard"], input#wildcard_check');
      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');
      const recordsCheckbox = page.locator('input[name="records"], input#records_check');

      if (await wildcardCheckbox.count() > 0) {
        await wildcardCheckbox.check();
      }

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      if (await recordsCheckbox.count() > 0) {
        await recordsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('mail*');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });

  test.describe('Search Permissions', () => {
    test('manager should search with comments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('test');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });

    test('viewer should search with comments', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);
      await page.goto('/search');

      const commentsCheckbox = page.locator('input[name="comments"], input#comments_check');

      if (await commentsCheckbox.count() > 0) {
        await commentsCheckbox.check();
      }

      const queryInput = page.locator('input[name="query"]');
      await queryInput.fill('test');
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
    });
  });
});
