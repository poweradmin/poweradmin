import { test } from '../fixtures/test-fixtures.js';
import users from '../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

/**
 * Cleanup script to remove any leftover test zones
 * Run this manually if tests fail and leave orphaned test data
 *
 * Usage: npx playwright test playwright/tests/cleanup-test-zones.spec.js
 */
test.describe('Cleanup Test Zones', () => {
  test('should remove all test zones created by automated tests', async ({ adminPage: page }) => {

    const testZonePatterns = [
      // From search-wildcard-patterns.spec.js
      'poweradmin.org',
      'poteradmin.org',
      'power-admin.org',
      'pot-admin.org',

      // From ptr-record-editing.spec.js
      'ptr-test.com',

      // From txt-record-escaping.spec.js
      'txt-escape-test.com',

      // From bulk-zone-validation.spec.js
      'bulktest',
      'validzone',
      'duplicate-test.com',
      'whitespace-test.com',
      'another-zone.org',
      'tldtest',

      // From pagination.spec.js
      'pagination-test-',
      'page-test-',
      'prev-test-',
      'num-test-',
      'records-pagination-test.com',

      // From login-redirects.spec.js
      'redirect-test.com',

      // From search-functionality.spec.js
      'search-test',

      // From concurrent tests
      'concurrent-test',

      // From test domain workflows
      'test-domain-',
    ];

    let deletedCount = 0;

    for (const pattern of testZonePatterns) {
      try {
        await page.goto('/index.php?page=list_zones');
        await page.waitForTimeout(500);

        // Find all zones matching the pattern
        const rows = await page.locator(`tr:has-text("${pattern}")`).all();

        for (const row of rows) {
          try {
            const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
            if (await deleteLink.count() > 0) {
              await deleteLink.first().click();

              // Wait for confirmation page
              await page.waitForTimeout(300);

              // Look for confirm button
              const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
              if (await confirmButton.count() > 0) {
                await confirmButton.first().click();
              }

              await page.waitForTimeout(500);
              deletedCount++;
              console.log(`Deleted zone matching: ${pattern}`);
            }
          } catch (e) {
            // Continue to next row
          }
        }
      } catch (e) {
        // Pattern not found, continue
      }
    }

    console.log(`\nTotal zones deleted: ${deletedCount}`);
  });
});
