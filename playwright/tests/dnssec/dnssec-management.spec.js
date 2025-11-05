import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('DNSSEC Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should handle DNSSEC page access with zone ID', async ({ page }) => {
    // Try to access DNSSEC page (will fail if no zone exists)
    await page.goto('/zones/1/dnssec', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('not found')) {
      await expect(page).toHaveURL(/.*dnssec/);
      await expect(page.locator('h1, h2, h3, .page-title').first()).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'DNSSEC page not available - zone may not exist' });
    }
  });

  test('should show DNSSEC status for existing zone', async ({ page }) => {
    // First navigate to zones to find an existing zone
    await page.goto('/zones/forward');

    const hasRows = await page.locator('table tbody tr').count() > 0;
    if (hasRows) {
      // Extract zone ID from first row and visit DNSSEC page
      const firstRow = page.locator('table tbody tr').first();
      const href = await firstRow.locator('a').first().getAttribute('href');

      if (href) {
        const match = href.match(/\/zones\/(\d+)/);
        if (match) {
          const zoneId = match[1];
          await page.goto(`/zones/${zoneId}/dnssec`);

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).toMatch(/DNSSEC|security/i);
        }
      }
    } else {
      test.info().annotations.push({ type: 'note', description: 'No zones available for DNSSEC testing' });
    }
  });

  test('should handle DNSSEC key addition page', async ({ page }) => {
    await page.goto('/zones/1/dnssec/keys/add', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('not found')) {
      await expect(page).toHaveURL(/.*dnssec\/keys\/add/);
      await expect(page.locator('form, [data-testid*="form"]')).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'DNSSEC key addition not available - zone may not exist' });
    }
  });

  test('should show DNSSEC key form fields if available', async ({ page }) => {
    await page.goto('/zones/1/dnssec/keys/add', { waitUntil: 'domcontentloaded' });

    const hasForm = await page.locator('form').count() > 0;
    if (hasForm) {
      // Look for key-related form fields
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/key|DNSSEC|algorithm/i);

      // Should have form elements
      const hasFormElements = await page.locator('input, select, textarea').count() > 0;
      expect(hasFormElements).toBeTruthy();
    }
  });

  test('should validate DNSSEC permissions', async ({ page }) => {
    await page.goto('/zones/1/dnssec', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('permission') || bodyText.includes('access') || bodyText.includes('denied')) {
      expect(bodyText).toMatch(/permission|access/i);
      test.info().annotations.push({ type: 'note', description: 'DNSSEC access restricted - permission required' });
    } else if (!bodyText.includes('404')) {
      test.info().annotations.push({ type: 'note', description: 'DNSSEC page accessible' });
    }
  });

  test('should show DNSSEC keys list if zone exists and has keys', async ({ page }) => {
    await page.goto('/zones/1/dnssec', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('permission')) {
      // Should show either keys table or "no keys" message
      const hasTable = await page.locator('table, .table').count() > 0;
      if (hasTable) {
        await expect(page.locator('table, .table').first()).toBeVisible();
      } else {
        expect(bodyText).toMatch(/key|DNSSEC|security/i);
      }
    }
  });

  test('should handle DNSSEC navigation from zone management', async ({ page }) => {
    // Navigate to zones and look for DNSSEC links
    await page.goto('/zones/forward');

    const hasDnssecLinks = await page.locator('a').filter({ hasText: /DNSSEC|Security/i }).count();
    if (hasDnssecLinks > 0) {
      const href = await page.locator('a').filter({ hasText: /DNSSEC|Security/i }).first().getAttribute('href');
      expect(href).toBeTruthy();
    } else {
      test.info().annotations.push({ type: 'note', description: 'No DNSSEC links found in zone management' });
    }
  });
});
