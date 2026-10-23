/**
 * Zone comment on a stale edit page
 *
 * The zone edit form sends the zone comment only when the operator changed it, so
 * saving an unrelated record change from a page opened earlier must not overwrite
 * a comment another writer saved in between.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { addRecord, createZone, deleteZoneById } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

test.describe('Zone comment on a stale edit page', () => {
  test('an untouched comment does not overwrite the other writer\'s comment', async ({ page, context }) => {
    test.setTimeout(90000);
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    const zoneName = `stale-comment-${Date.now()}.example.com`;
    const zoneId = await createZone(page, zoneName);
    expect(zoneId, `zone ${zoneName} must be created`).toBeTruthy();

    try {
      await addRecord(page, zoneId, { type: 'A', name: 'www', content: '192.0.2.10' });

      // Writer A opens the editor while the zone comment is still empty
      await page.goto(`/zones/${zoneId}/edit`);
      const commentField = page.locator('textarea[name="zone_comment"]');
      test.skip(await commentField.count() === 0, 'zone comments are disabled on this instance (show_zone_comments)');

      // Writer B saves a comment through the same editor
      const other = await context.newPage();
      await other.goto(`/zones/${zoneId}/edit`);
      await other.locator('textarea[name="zone_comment"]').fill('from B');
      await Promise.all([
        other.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(`/zones/${zoneId}/edit`)),
        other.locator('[data-testid="save-changes-button"]').first().click(),
      ]);
      await other.close();

      // A changes a record only and saves
      await page.locator('input[name$="[content]"][value="192.0.2.10"]').fill('192.0.2.11');
      await Promise.all([
        page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(`/zones/${zoneId}/edit`)),
        page.locator('[data-testid="save-changes-button"]').first().click(),
      ]);

      await page.goto(`/zones/${zoneId}/edit`);
      await expect(page.locator('textarea[name="zone_comment"]')).toHaveValue('from B');
    } finally {
      await deleteZoneById(page, zoneId);
    }
  });
});
