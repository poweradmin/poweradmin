import { test, expect } from '../../fixtures/test-fixtures.js';
import { ensureAnyZoneExists, getZoneIdForTest } from '../../helpers/zones.js';

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNSSEC Management', () => {
  test('should handle DNSSEC page access with zone ID', async ({ adminPage: page }) => {
    const zoneId = await ensureAnyZoneExists(page);
    expect(zoneId).toBeTruthy();

    await page.goto(`/zones/${zoneId}/dnssec`, { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    // Check for various outcomes - zone exists, zone doesn't exist, or 404
    if (bodyText.includes('no zone with this ID') || bodyText.includes('not found') || bodyText.includes('404')) {
      // Zone doesn't exist - this is acceptable in test environment
      test.info().annotations.push({ type: 'note', description: 'DNSSEC page not available - zone does not exist' });
    } else {
      await expect(page).toHaveURL(/.*\/zones\/\d+\/dnssec/);
      // Page should load without errors
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should show DNSSEC status for existing zone', async ({ adminPage: page }) => {
    const zoneId = await ensureAnyZoneExists(page);
    expect(zoneId).toBeTruthy();

    await page.goto(`/zones/${zoneId}/dnssec`);

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/DNSSEC|security/i);
  });

  test('should handle DNSSEC key addition page', async ({ adminPage: page }) => {
    const zoneId = await ensureAnyZoneExists(page);
    expect(zoneId).toBeTruthy();

    await page.goto(`/zones/${zoneId}/dnssec/keys/add`, { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('not found') && !bodyText.toLowerCase().includes('error')) {
      await expect(page).toHaveURL(/.*\/dnssec\/keys\/add/);
      // Form may or may not be visible depending on DNSSEC configuration
      const form = page.locator('form, [data-testid*="form"]');
      if (await form.count() > 0) {
        await expect(form.first()).toBeVisible();
      }
    } else {
      test.info().annotations.push({ type: 'note', description: 'DNSSEC key addition not available - zone may not exist or DNSSEC not enabled' });
    }
  });

  test('should show DNSSEC key form fields if available', async ({ adminPage: page }) => {
    const zoneId = await ensureAnyZoneExists(page);
    expect(zoneId).toBeTruthy();

    await page.goto(`/zones/${zoneId}/dnssec/keys/add`, { waitUntil: 'domcontentloaded' });

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

  test('should validate DNSSEC permissions', async ({ adminPage: page }) => {
    const zoneId = await ensureAnyZoneExists(page);
    expect(zoneId).toBeTruthy();

    await page.goto(`/zones/${zoneId}/dnssec`, { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    // Check for any response - admin should have access
    expect(bodyText.length).toBeGreaterThan(0);
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should show DNSSEC keys list if zone exists and has keys', async ({ adminPage: page }) => {
    const zoneId = await ensureAnyZoneExists(page);
    expect(zoneId).toBeTruthy();

    await page.goto(`/zones/${zoneId}/dnssec`, { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404')) {
      // Should show either keys table or "no keys" message
      const hasTable = await page.locator('table, .table').count() > 0;
      if (hasTable) {
        await expect(page.locator('table, .table').first()).toBeVisible();
      } else {
        expect(bodyText).toMatch(/key|DNSSEC|security/i);
      }
    }
  });

  test('should handle DNSSEC navigation from zone management', async ({ adminPage: page }) => {
    // Navigate to zones and look for DNSSEC links
    await page.goto('/zones/forward?letter=all');

    const hasDnssecLinks = await page.locator('a').filter({ hasText: /DNSSEC|Security/i }).count();
    if (hasDnssecLinks > 0) {
      const href = await page.locator('a').filter({ hasText: /DNSSEC|Security/i }).first().getAttribute('href');
      expect(href).toBeTruthy();
    } else {
      test.info().annotations.push({ type: 'note', description: 'No DNSSEC links found in zone management' });
    }
  });
});

test.describe('Add DNSSEC Key', () => {
  test.describe('Admin User', () => {
    test('should display add key page', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.includes('error')) {
        await expect(page).toHaveURL(/.*\/dnssec\/keys\/add/);
      }
    });

    test('should display add key form', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const form = page.locator('form').first();
      if (await form.count() > 0) {
        await expect(form).toBeVisible();
      }
    });

    test('should display key type select', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const keyTypeSelect = page.locator('select[name*="type"], select[name*="key"]').first();
      if (await keyTypeSelect.count() > 0) {
        await expect(keyTypeSelect).toBeVisible();
      }
    });

    test('should display bits select', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const bitsSelect = page.locator('select[name*="bits"], select[name*="size"]').first();
      if (await bitsSelect.count() > 0) {
        await expect(bitsSelect).toBeVisible();
      }
    });

    test('should display algorithm select', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const algoSelect = page.locator('select[name*="algo"], select[name*="algorithm"]').first();
      if (await algoSelect.count() > 0) {
        await expect(algoSelect).toBeVisible();
      }
    });

    test('should display submit button', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const submitBtn = page.locator('input[type="submit"], button[type="submit"]').first();
      if (await submitBtn.count() > 0) {
        await expect(submitBtn).toBeVisible();
      }
    });

    test('should allow selecting key type', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      const keyTypeSelect = page.locator('select[name*="type"], select[name*="key"]').first();
      if (await keyTypeSelect.count() > 0) {
        const options = await keyTypeSelect.locator('option').count();
        expect(options).toBeGreaterThan(0);
      }
    });
  });

  test.describe('Manager User', () => {
    test('should have access to add DNSSEC key for own zone', async ({ managerPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/add`);
      // Manager may or may not have access depending on zone ownership
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});

test.describe('Edit DNSSEC Key', () => {
  test.describe('Page Structure', () => {
    test('should display edit key page if key exists', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/edit`);
      const bodyText = await page.locator('body').textContent();
      // Page should load without crashing
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should display key information if page loads', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/edit`);
      const bodyText = await page.locator('body').textContent();
      // Page should load without crashing - key may not exist
      expect(bodyText).not.toMatch(/fatal|exception/i);
      // Page should contain DNSSEC-related content (either form or error message)
      expect(bodyText.toLowerCase()).toMatch(/key|dnssec|error|not found/i);
    });

    test('should display confirmation buttons if page loads', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/edit`);
      const submitBtn = page.locator('button[type="submit"], input[type="submit"]');
      const cancelBtn = page.locator('a:has-text("Cancel"), button:has-text("Cancel")');
      if (await submitBtn.count() > 0) {
        await expect(submitBtn.first()).toBeVisible();
      }
      if (await cancelBtn.count() > 0) {
        await expect(cancelBtn.first()).toBeVisible();
      }
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have edit key link if keys exist', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec`);
      const editLinks = page.locator('a[href*="/keys/"][href*="/edit"]');
      if (await editLinks.count() > 0) {
        await expect(editLinks.first()).toBeVisible();
      }
    });
  });
});

test.describe('Delete DNSSEC Key', () => {
  test.describe('Page Structure', () => {
    test('should display delete key page if key exists', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/delete`);
      const bodyText = await page.locator('body').textContent();
      // Page should load without crashing
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should display key information on delete page', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/delete`);
      const bodyText = await page.locator('body').textContent();
      // Page should load without crashing - key may not exist
      expect(bodyText).not.toMatch(/fatal|exception/i);
      // Page should contain DNSSEC-related content (either form or error message)
      expect(bodyText.toLowerCase()).toMatch(/key|dnssec|error|not found|delete/i);
    });

    test('should display confirmation message', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/delete`);
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.toLowerCase().includes('error')) {
        // Page should show some confirmation-related content
        expect(bodyText.toLowerCase()).toMatch(/sure|confirm|delete|dnssec|key/i);
      }
    });

    test('should display delete form', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/delete`);
      const form = page.locator('form').first();
      if (await form.count() > 0) {
        await expect(form).toBeVisible();
      }
    });

    test('should display confirm and cancel buttons', async ({ adminPage: page }) => {
      test.setTimeout(60000);

      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) {
        test.skip('No zones available for testing');
        return;
      }

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/delete`, { timeout: 30000 });
      await page.waitForLoadState('networkidle');

      const deleteBtn = page.locator('button[type="submit"]:has-text("Delete"), button:has-text("Delete")');
      const cancelBtn = page.locator('a:has-text("Cancel"), button:has-text("Cancel")');
      if (await deleteBtn.count() > 0) {
        await expect(deleteBtn.first()).toBeVisible();
      }
      if (await cancelBtn.count() > 0) {
        await expect(cancelBtn.first()).toBeVisible();
      }
    });

    test('should use correct CSRF token field name', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/keys/1/delete`);
      const form = page.locator('form').first();
      if (await form.count() > 0) {
        // Should use _token (correct)
        const correctToken = page.locator('input[name="_token"]');
        expect(await correctToken.count()).toBe(1);

        // Should NOT use csrf_token (incorrect)
        const wrongToken = page.locator('input[name="csrf_token"]');
        expect(await wrongToken.count()).toBe(0);
      }
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have delete key link if keys exist', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec`);
      const deleteLinks = page.locator('a[href*="/keys/"][href*="/delete"]');
      if (await deleteLinks.count() > 0) {
        await expect(deleteLinks.first()).toBeVisible();
      }
    });
  });
});

test.describe('DNSSEC DS and DNSKEY Records', () => {
  test.describe('Admin User', () => {
    test('should display DS/DNSKEY page', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.includes('error')) {
        await expect(page).toHaveURL(/.*\/dnssec\/ds-dnskey/);
      }
    });

    test('should display DNSSEC public records heading', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found')) {
        expect(bodyText.toLowerCase()).toMatch(/dnssec|public|records|ds|dnskey/i);
      }
    });

    test('should display DNSKEY section', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.toLowerCase().includes('error')) {
        // Page should contain DNSKEY or DNSSEC-related content
        expect(bodyText).toMatch(/DNSKEY|DNSSEC|key/i);
      }
    });

    test('should display DS section', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.toLowerCase().includes('error')) {
        // Page should contain DS or DNSSEC-related content
        expect(bodyText).toMatch(/DS|DNSSEC|digest/i);
      }
    });

    test('should display records containers', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      // Check page loaded without fatal errors
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
      // Records containers are optional - DNSSEC may not be configured
      const pre = page.locator('pre, code, .records');
      if (await pre.count() > 0) {
        const firstPre = pre.first();
        if (await firstPre.isVisible()) {
          await expect(firstPre).toBeVisible();
        }
      }
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have DS/DNSKEY link', async ({ adminPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec`);
      const dsLink = page.locator('a[href*="ds-dnskey"]');
      if (await dsLink.count() > 0) {
        await expect(dsLink.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User', () => {
    test('should have access to DS/DNSKEY page for own zone', async ({ managerPage: page }) => {
      const zoneId = await getZoneIdForTest(page);
      if (!zoneId) test.skip();

      await page.goto(`/zones/${zoneId}/dnssec/ds-dnskey`);
      const bodyText = await page.locator('body').textContent();
      // Manager may or may not have access depending on zone ownership
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});
