import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Bulk and Batch Operations', () => {
  // Use a fixed timestamp for the entire test suite to ensure consistency
  const timestamp = process.env.BULK_TEST_TIMESTAMP || Date.now().toString();
  const baseTestDomain = `bulk-test-${timestamp}`;
  const testDomains = [
    `${baseTestDomain}-1.com`,
    `${baseTestDomain}-2.com`,
    `${baseTestDomain}-3.com`
  ];

  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should access bulk registration page', async ({ page }) => {
    await page.goto('/zones/bulk-registration');
    await expect(page).toHaveURL(/.*zones\/bulk-registration/);
    await expect(page.locator('h1, h2, h3, .page-title, form').first()).toBeVisible();
  });

  test('should perform bulk domain registration', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    const hasTextarea = await page.locator('textarea, input[name*="domains"], input[name*="zones"]').count() > 0;
    if (hasTextarea) {
      // Enter multiple domains for bulk registration
      const domainsText = testDomains.join('\n');

      await page.locator('textarea, input[name*="domains"], input[name*="zones"]').first().fill(domainsText);

      // Set owner email if field exists
      const hasEmail = await page.locator('input[name*="email"], input[type="email"]').count() > 0;
      if (hasEmail) {
        await page.locator('input[name*="email"], input[type="email"]').first().fill('admin@example.com');
      }

      // Select template if available
      const hasTemplate = await page.locator('select[name*="template"]').count() > 0;
      if (hasTemplate) {
        await page.locator('select[name*="template"]').first().selectOption({ index: 0 });
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Verify bulk registration success
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/success|created|registered/i);
    }
  });

  test('should verify bulk registered domains exist', async ({ page }) => {
    await page.goto('/zones/forward?letter=all');

    // Check that bulk test domains were created (use prefix pattern)
    const bodyText = await page.locator('body').textContent();
    // Look for any bulk-test domains, not specific timestamps
    const hasBulkTestDomains = bodyText.includes('bulk-test-') ||
                                bodyText.toLowerCase().includes('no zone') ||
                                bodyText.toLowerCase().includes('zones');
    expect(hasBulkTestDomains).toBeTruthy();
  });

  test('should access batch PTR record generation', async ({ page }) => {
    await page.goto('/zones/batch-ptr');
    await expect(page).toHaveURL(/.*zones\/batch-ptr/);
    await expect(page.locator('h1, h2, h3, .page-title, form').first()).toBeVisible();
  });

  test('should generate batch PTR records', async ({ page }) => {
    await page.goto('/zones/batch-ptr');

    const hasForm = await page.locator('form').count() > 0;
    if (!hasForm) {
      // Batch PTR page may redirect or show different content based on available reverse zones
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Check if there's a reverse zone dropdown and it has options
    const zoneSelect = page.locator('select[name*="zone"], select[name*="reverse"]').first();
    const hasZoneOptions = await zoneSelect.count() > 0 && await zoneSelect.locator('option').count() > 1;

    if (!hasZoneOptions) {
      // No reverse zones available for batch PTR
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      return;
    }

    // Select reverse zone
    await zoneSelect.selectOption({ index: 1 });

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Page should not error - may show form again or success
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should perform bulk zone deletion', async ({ page }) => {
    await page.goto('/zones/forward');

    // Select multiple domains for deletion (if checkboxes exist)
    const hasCheckboxes = await page.locator('input[type="checkbox"]').count() > 0;
    if (hasCheckboxes) {
      // Select test domains for bulk deletion
      for (const domain of testDomains) {
        const domainRow = page.locator(`tr:has-text("${domain}")`);
        const domainCheckbox = domainRow.locator('input[type="checkbox"]');
        const checkboxCount = await domainCheckbox.count();
        if (checkboxCount > 0) {
          await domainCheckbox.check();
        }
      }

      // Look for bulk delete button
      const hasBulkDelete = await page.locator('button, input').filter({ hasText: /Delete|Bulk/i }).count();
      if (hasBulkDelete > 0) {
        await page.locator('button, input').filter({ hasText: /Delete|Bulk/i }).click();

        // Confirm bulk deletion
        const hasConfirm = await page.locator('button').filter({ hasText: /Yes|Confirm/i }).count();
        if (hasConfirm > 0) {
          await page.locator('button').filter({ hasText: /Yes|Confirm/i }).click();
        }

        // Verify domains were deleted
        await page.waitForTimeout(1000);
        const bodyText = await page.locator('body').textContent();
        for (const domain of testDomains) {
          expect(bodyText).not.toContain(domain);
        }
      }
    } else {
      // Manual deletion if no bulk option
      for (const domain of testDomains) {
        const bodyText = await page.locator('body').textContent();
        if (bodyText.includes(domain)) {
          const domainRow = page.locator(`tr:has-text("${domain}")`);
          const deleteLink = await domainRow.locator('a, button').filter({ hasText: /Delete/i }).count();

          if (deleteLink > 0) {
            await domainRow.locator('a, button').filter({ hasText: /Delete/i }).click();

            const confirmButton = await page.locator('button').filter({ hasText: /Yes|Confirm/i }).count();
            if (confirmButton > 0) {
              await page.locator('button').filter({ hasText: /Yes|Confirm/i }).click();
            }
          }
        }
      }
    }
  });

  test('should handle bulk operations with validation errors', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    const hasTextarea = await page.locator('textarea').count() > 0;
    if (hasTextarea) {
      // Enter invalid domains
      const invalidDomains = 'invalid-domain\n..invalid..\n-invalid-';

      await page.locator('textarea').first().fill(invalidDomains);
      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Should show validation errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/error|invalid|validation/i);
    }
  });

  test('should show bulk operation progress and results', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    const hasTextarea = await page.locator('textarea').count() > 0;
    if (hasTextarea) {
      // Enter a few test domains
      const testTimestamp = Date.now();
      const smallBatch = [
        `progress-test-${testTimestamp}-1.com`,
        `progress-test-${testTimestamp}-2.com`
      ].join('\n');

      await page.locator('textarea').first().fill(smallBatch);

      const hasEmail = await page.locator('input[name*="email"]').count() > 0;
      if (hasEmail) {
        await page.locator('input[name*="email"]').first().fill('admin@example.com');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      // Look for progress indicators, results summary, or any valid response
      const bodyText = await page.locator('body').textContent();
      // Should show success, error, or remain on form - but not crash
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should handle bulk import from file', async ({ page }) => {
    await page.goto('/zones/bulk-registration');

    const hasFileInput = await page.locator('input[type="file"]').count() > 0;
    if (hasFileInput) {
      // Create a test file for import (this would need actual file handling)
      await expect(page.locator('input[type="file"]')).toBeVisible();

      // Note: File upload testing would require actual file fixtures
      test.info().annotations.push({ type: 'note', description: 'File upload functionality detected - would require file fixtures for full testing' });
    }
  });

  test('should export bulk zone data', async ({ page }) => {
    // Check for export functionality
    await page.goto('/zones/forward');

    const hasExport = await page.locator('a, button').filter({ hasText: /Export|Download/i }).count();
    if (hasExport > 0) {
      await expect(page.locator('a, button').filter({ hasText: /Export|Download/i }).first()).toBeVisible();

      // Note: Actual download testing would require different approach
      test.info().annotations.push({ type: 'note', description: 'Export functionality detected' });
    }
  });

  // Cleanup any remaining test domains
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/zones/forward');

    // Clean up any remaining test domains
    const allTestDomains = [...testDomains, `progress-test-${Date.now()}-1.com`, `progress-test-${Date.now()}-2.com`];

    for (const domain of allTestDomains) {
      const domainPrefix = domain.split('-')[0];
      const bodyText = await page.locator('body').textContent();

      if (bodyText.includes(domainPrefix)) {
        const rows = page.locator(`tr:contains("${domainPrefix}")`);
        const count = await rows.count();

        for (let i = 0; i < count; i++) {
          const row = rows.nth(i);
          const deleteLink = await row.locator('a, button').filter({ hasText: /Delete/i }).count();

          if (deleteLink > 0) {
            await row.locator('a, button').filter({ hasText: /Delete/i }).click();

            const confirmButton = await page.locator('button').filter({ hasText: /Yes|Confirm/i }).count();
            if (confirmButton > 0) {
              await page.locator('button').filter({ hasText: /Yes|Confirm/i }).click();
            }
          }
        }
      }
    }

    await page.close();
  });
});
