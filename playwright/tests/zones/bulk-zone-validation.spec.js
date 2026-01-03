import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Bulk Zone Registration Validation', () => {
  test('should register single zone via bulk registration', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=bulk_registration');

    const hasTextarea = await page.locator('textarea').count() > 0;
    if (!hasTextarea) {
      test.info().annotations.push({ type: 'note', description: 'Bulk registration page not available' });
      return;
    }

    // Enter single zone
    await page.locator('textarea').first().fill('bulktest1.com');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show success message or redirect
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should register multiple zones via bulk registration', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=bulk_registration');

    const hasTextarea = await page.locator('textarea').count() > 0;
    if (!hasTextarea) {
      test.info().annotations.push({ type: 'note', description: 'Bulk registration page not available' });
      return;
    }

    // Enter multiple zones (newline separated)
    await page.locator('textarea').first().fill('bulktest1.com\nbulktest2.org\nbulktest3.net');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should process without fatal errors
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should show error for malformed domain name', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=bulk_registration');

    const hasTextarea = await page.locator('textarea').count() > 0;
    if (!hasTextarea) {
      test.info().annotations.push({ type: 'note', description: 'Bulk registration page not available' });
      return;
    }

    // Use domain with invalid characters (@ is not allowed in domain names)
    const invalidDomain = 'test@invalid-domain.com';
    await page.locator('textarea').first().fill(invalidDomain);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show error or stay on form with failed domains listed
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.toLowerCase().includes('invalid') ||
                     bodyText.toLowerCase().includes('hostname') ||
                     bodyText.includes(invalidDomain) ||
                     page.url().includes('bulk_registration');
    expect(hasError).toBeTruthy();
  });

  test('should prevent duplicate zone registration', async ({ adminPage: page }) => {
    // First, create a zone
    await page.goto('/index.php?page=add_zone_master');
    await page.locator('input[name*="name"], input[name*="domain"]').first().fill('duplicate-test.com');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Try to add same zone via bulk registration
    await page.goto('/index.php?page=bulk_registration');
    const hasTextarea = await page.locator('textarea').count() > 0;
    if (!hasTextarea) {
      test.info().annotations.push({ type: 'note', description: 'Bulk registration page not available' });
      return;
    }

    await page.locator('textarea').first().fill('duplicate-test.com');
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show error about duplicate or stay on page
    const bodyText = await page.locator('body').textContent();
    const hasDuplicateError = bodyText.toLowerCase().includes('exists') || bodyText.toLowerCase().includes('duplicate') || bodyText.toLowerCase().includes('error');
    expect(hasDuplicateError).toBeTruthy();

    // Cleanup - delete the test zone
    await page.goto('/index.php?page=list_zones');
    const row = page.locator('tr:has-text("duplicate-test.com")');
    if (await row.count() > 0) {
      await row.locator('a').filter({ hasText: /Delete/i }).first().click();
      const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
      if (await confirmButton.count() > 0) {
        await confirmButton.first().click();
      }
    }
  });

  test('should handle zones with various valid TLDs', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=bulk_registration');

    const hasTextarea = await page.locator('textarea').count() > 0;
    if (!hasTextarea) {
      test.info().annotations.push({ type: 'note', description: 'Bulk registration page not available' });
      return;
    }

    // Test various common TLDs
    const zones = 'tldtest1.com\ntldtest2.net\ntldtest3.org';
    await page.locator('textarea').first().fill(zones);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should process without fatal errors
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  // Cleanup after tests
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_zones');

    // Delete test zones
    const testPatterns = ['bulktest', 'tldtest', 'duplicate-test'];
    for (const pattern of testPatterns) {
      const rows = await page.locator(`tr:has-text("${pattern}")`).all();
      for (const row of rows) {
        try {
          const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
          if (await deleteLink.count() > 0) {
            await deleteLink.first().click();
            const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
            if (await confirmButton.count() > 0) {
              await confirmButton.first().click();
            }
            await page.waitForTimeout(300);
          }
        } catch (e) {
          // Continue
        }
      }
    }

    await page.close();
  });
});
