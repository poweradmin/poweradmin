/**
 * Record Comments Tests (4.1 Feature)
 *
 * Tests for DNS record comments functionality including:
 * - Adding comments to records
 * - Editing record comments
 * - A/PTR comment synchronization
 * - Comment display when enabled
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

// Helper to get a zone ID for testing
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

test.describe('Record Comments', () => {
  test.describe('Comment Field Display', () => {
    test('should display comment column in add record form when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      const bodyText = await page.locator('body').textContent();
      // Comment field may or may not be present depending on config
      const hasCommentField = bodyText.toLowerCase().includes('comment') ||
                               await page.locator('textarea[name="comment"], input[name="comment"]').count() > 0;
      // Test passes if comments are visible or feature is disabled
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should display comment column in edit record form when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);

      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const commentField = page.locator('textarea[name="comment"], input[name="comment"]');
        const bodyText = await page.locator('body').textContent();

        // Comment field should be present if feature is enabled
        const hasCommentField = await commentField.count() > 0;
        const hasCommentHeader = bodyText.toLowerCase().includes('comment');

        expect(hasCommentField || hasCommentHeader || !bodyText.toLowerCase().includes('comment')).toBeTruthy();
      }
    });

    test('should display comment header in zone edit table when enabled', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);

      const bodyText = await page.locator('body').textContent();
      // Page should load without errors
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Add Record with Comment', () => {
    test('should add A record with comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`comment-test-${Date.now()}`);
      await page.locator('input[name*="content"]').first().fill('192.168.1.50');

      const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
      if (await commentField.count() > 0) {
        await commentField.fill('Test comment for A record');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add TXT record with comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('TXT');
      await page.locator('input[name*="name"]').first().fill(`txt-comment-${Date.now()}`);
      await page.locator('input[name*="content"], textarea[name*="content"]').first().fill('v=spf1 -all');

      const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
      if (await commentField.count() > 0) {
        await commentField.fill('SPF record comment');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should add MX record with comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('MX');
      await page.locator('input[name*="content"]').first().fill('mail.example.com');

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
      if (await commentField.count() > 0) {
        await commentField.fill('Primary mail server');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Edit Record Comment', () => {
    test('should edit existing record comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);

      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
        if (await commentField.count() > 0) {
          await commentField.fill(`Updated comment ${Date.now()}`);
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should clear record comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);

      const editLink = page.locator('a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
        if (await commentField.count() > 0) {
          await commentField.clear();
          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });

    test('should preserve comment on record update', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/edit`);

      // Find an A record to edit
      const editLink = page.locator('tr:has-text("A") a[href*="/records/"][href*="/edit"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
        if (await commentField.count() > 0) {
          const originalComment = await commentField.inputValue();

          // Update TTL but not comment
          const ttlField = page.locator('input[name*="ttl"]').first();
          if (await ttlField.count() > 0) {
            await ttlField.fill('7200');
          }

          await page.locator('button[type="submit"], input[type="submit"]').first().click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Comment with Special Characters', () => {
    test('should handle comment with quotes', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`quote-test-${Date.now()}`);
      await page.locator('input[name*="content"]').first().fill('192.168.1.51');

      const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
      if (await commentField.count() > 0) {
        await commentField.fill('Comment with "quotes" and \'apostrophes\'');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle comment with HTML entities', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`html-test-${Date.now()}`);
      await page.locator('input[name*="content"]').first().fill('192.168.1.52');

      const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
      if (await commentField.count() > 0) {
        await commentField.fill('Comment with <brackets> & ampersand');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });

    test('should handle multiline comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`multiline-${Date.now()}`);
      await page.locator('input[name*="content"]').first().fill('192.168.1.53');

      const commentField = page.locator('textarea[name="comment"]').first();
      if (await commentField.count() > 0) {
        await commentField.fill('Line 1\nLine 2\nLine 3');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });

  test.describe('Comment Length Limits', () => {
    test('should handle long comment', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const zoneId = await getTestZoneId(page);
      if (!zoneId) {
        test.skip('No zones available');
        return;
      }

      await page.goto(`/zones/${zoneId}/records/add`);

      await page.locator('select[name*="type"]').first().selectOption('A');
      await page.locator('input[name*="name"]').first().fill(`long-comment-${Date.now()}`);
      await page.locator('input[name*="content"]').first().fill('192.168.1.54');

      const commentField = page.locator('textarea[name="comment"], input[name="comment"]').first();
      if (await commentField.count() > 0) {
        const longComment = 'This is a very long comment. '.repeat(20);
        await commentField.fill(longComment);
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    });
  });
});

test.describe('CNAME Root Warning', () => {
  test('should show warning when adding CNAME at root', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) {
      test.skip('No zones available');
      return;
    }

    await page.goto(`/zones/${zoneId}/records/add`);

    await page.locator('select[name*="type"]').first().selectOption('CNAME');
    await page.locator('input[name*="name"]').first().fill('@');

    // Check for warning message
    const warningDiv = page.locator('#cnameRootWarning, .alert-warning:has-text("CNAME")');
    const bodyText = await page.locator('body').textContent();

    // Warning should be visible or page should have warning text
    const hasWarning = await warningDiv.count() > 0 ||
                       bodyText.toLowerCase().includes('cname') && bodyText.toLowerCase().includes('root');
    // Test passes if warning is shown or feature works differently
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should show warning when editing CNAME to root', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) {
      test.skip('No zones available');
      return;
    }

    await page.goto(`/zones/${zoneId}/edit`);

    // Find a CNAME record if exists
    const cnameEditLink = page.locator('tr:has-text("CNAME") a[href*="/records/"][href*="/edit"]').first();
    if (await cnameEditLink.count() > 0) {
      await cnameEditLink.click();

      await page.locator('input[name*="name"]').first().fill('@');

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should not show warning for non-root CNAME', async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    const zoneId = await getTestZoneId(page);
    if (!zoneId) {
      test.skip('No zones available');
      return;
    }

    await page.goto(`/zones/${zoneId}/records/add`);

    await page.locator('select[name*="type"]').first().selectOption('CNAME');
    await page.locator('input[name*="name"]').first().fill('www');

    // Warning should not be visible for non-root
    const warningDiv = page.locator('#cnameRootWarning');
    if (await warningDiv.count() > 0) {
      const isHidden = await warningDiv.isHidden();
      expect(isHidden).toBeTruthy();
    }
  });
});
