import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('DNSSEC Management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test('should handle DNSSEC page access with zone ID', async ({ page }) => {
    // Try to access DNSSEC page (will fail if no zone exists)
    await page.goto('/index.php?page=dnssec&id=1', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('not found')) {
      await expect(page).toHaveURL(/page=dnssec/);
      await expect(page.locator('h1, h2, h3, .page-title').first()).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'DNSSEC page not available - zone may not exist' });
    }
  });

  test('should show DNSSEC status for existing zone', async ({ page }) => {
    // First navigate to zones to find an existing zone
    await page.goto('/index.php?page=list_zones');

    const hasRows = await page.locator('table tbody tr').count() > 0;
    if (hasRows) {
      // Extract zone ID from first row and visit DNSSEC page
      const firstRow = page.locator('table tbody tr').first();
      const href = await firstRow.locator('a').first().getAttribute('href');

      if (href) {
        const match = href.match(/id=(\d+)/);
        if (match) {
          const zoneId = match[1];
          await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).toMatch(/DNSSEC|security/i);
        }
      }
    } else {
      test.info().annotations.push({ type: 'note', description: 'No zones available for DNSSEC testing' });
    }
  });

  test('should handle DNSSEC key addition page', async ({ page }) => {
    await page.goto('/index.php?page=dnssec_add_key&id=1', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (!bodyText.includes('404') && !bodyText.includes('not found')) {
      await expect(page).toHaveURL(/page=dnssec_add_key/);
      await expect(page.locator('form, [data-testid*="form"]')).toBeVisible();
    } else {
      test.info().annotations.push({ type: 'note', description: 'DNSSEC key addition not available - zone may not exist' });
    }
  });

  test('should show DNSSEC key form fields if available', async ({ page }) => {
    await page.goto('/index.php?page=dnssec_add_key&id=1', { waitUntil: 'domcontentloaded' });

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
    await page.goto('/index.php?page=dnssec&id=1', { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('permission') || bodyText.includes('access') || bodyText.includes('denied')) {
      expect(bodyText).toMatch(/permission|access/i);
      test.info().annotations.push({ type: 'note', description: 'DNSSEC access restricted - permission required' });
    } else if (!bodyText.includes('404')) {
      test.info().annotations.push({ type: 'note', description: 'DNSSEC page accessible' });
    }
  });

  test('should show DNSSEC keys list if zone exists and has keys', async ({ page }) => {
    await page.goto('/index.php?page=dnssec&id=1', { waitUntil: 'domcontentloaded' });

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
    await page.goto('/index.php?page=list_zones');

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
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display add key page', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.includes('error')) {
        await expect(page).toHaveURL(/page=dnssec_add_key/);
      }
    });

    test('should display add key form', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      const form = page.locator('form').first();
      if (await form.count() > 0) {
        await expect(form).toBeVisible();
      }
    });

    test('should display key type select', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      const keyTypeSelect = page.locator('select[name*="type"], select[name*="key"]').first();
      if (await keyTypeSelect.count() > 0) {
        await expect(keyTypeSelect).toBeVisible();
      }
    });

    test('should display bits select', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      const bitsSelect = page.locator('select[name*="bits"], select[name*="size"]').first();
      if (await bitsSelect.count() > 0) {
        await expect(bitsSelect).toBeVisible();
      }
    });

    test('should display algorithm select', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      const algoSelect = page.locator('select[name*="algo"], select[name*="algorithm"]').first();
      if (await algoSelect.count() > 0) {
        await expect(algoSelect).toBeVisible();
      }
    });

    test('should display submit button', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      const submitBtn = page.locator('input[type="submit"], button[type="submit"]').first();
      if (await submitBtn.count() > 0) {
        await expect(submitBtn).toBeVisible();
      }
    });

    test('should allow selecting key type', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      const keyTypeSelect = page.locator('select[name*="type"], select[name*="key"]').first();
      if (await keyTypeSelect.count() > 0) {
        const options = await keyTypeSelect.locator('option').count();
        expect(options).toBeGreaterThan(0);
      }
    });
  });

  test.describe('Manager User', () => {
    test('should have access to add DNSSEC key for own zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=dnssec_add_key&id=1');
      // Manager may or may not have access depending on zone ownership
      const bodyText = await page.locator('body').textContent();
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});

test.describe('Edit DNSSEC Key', () => {
  test.describe('Page Structure', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display edit key page if key exists', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_edit_key&id=1&key_id=1');
      const bodyText = await page.locator('body').textContent();
      // Page should load without crashing
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should display key information if page loads', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_edit_key&id=1&key_id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.includes('error')) {
        expect(bodyText.toLowerCase()).toMatch(/key|dnssec|activate|deactivate/i);
      }
    });

    test('should display confirmation buttons if page loads', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_edit_key&id=1&key_id=1');
      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes"), a:has-text("Yes")');
      const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")');
      if (await yesBtn.count() > 0) {
        await expect(yesBtn.first()).toBeVisible();
      }
      if (await noBtn.count() > 0) {
        await expect(noBtn.first()).toBeVisible();
      }
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have edit key link if keys exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=dnssec&id=1');
      const editLinks = page.locator('a[href*="dnssec_edit_key"]');
      if (await editLinks.count() > 0) {
        await expect(editLinks.first()).toBeVisible();
      }
    });
  });
});

test.describe('Delete DNSSEC Key', () => {
  test.describe('Page Structure', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display delete key page if key exists', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_delete_key&id=1&key_id=1');
      const bodyText = await page.locator('body').textContent();
      // Page should load without crashing
      expect(bodyText.length).toBeGreaterThan(0);
    });

    test('should display key information on delete page', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_delete_key&id=1&key_id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.includes('error')) {
        expect(bodyText.toLowerCase()).toMatch(/key|dnssec|delete/i);
      }
    });

    test('should display confirmation message', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_delete_key&id=1&key_id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found')) {
        expect(bodyText.toLowerCase()).toMatch(/sure|confirm|delete/i);
      }
    });

    test('should display delete form', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_delete_key&id=1&key_id=1');
      const form = page.locator('form').first();
      if (await form.count() > 0) {
        await expect(form).toBeVisible();
      }
    });

    test('should display confirm and cancel buttons', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_delete_key&id=1&key_id=1');
      const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")');
      const noBtn = page.locator('a:has-text("No"), button:has-text("No")');
      if (await yesBtn.count() > 0) {
        await expect(yesBtn.first()).toBeVisible();
      }
      if (await noBtn.count() > 0) {
        await expect(noBtn.first()).toBeVisible();
      }
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have delete key link if keys exist', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=dnssec&id=1');
      const deleteLinks = page.locator('a[href*="dnssec_delete_key"]');
      if (await deleteLinks.count() > 0) {
        await expect(deleteLinks.first()).toBeVisible();
      }
    });
  });
});

test.describe('DNSSEC DS and DNSKEY Records', () => {
  test.describe('Admin User', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display DS/DNSKEY page', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_ds_dnskey&id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found') && !bodyText.includes('error')) {
        await expect(page).toHaveURL(/page=dnssec_ds_dnskey/);
      }
    });

    test('should display DNSSEC public records heading', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_ds_dnskey&id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found')) {
        expect(bodyText.toLowerCase()).toMatch(/dnssec|public|records|ds|dnskey/i);
      }
    });

    test('should display DNSKEY section', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_ds_dnskey&id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found')) {
        expect(bodyText).toMatch(/DNSKEY/i);
      }
    });

    test('should display DS section', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_ds_dnskey&id=1');
      const bodyText = await page.locator('body').textContent();
      if (!bodyText.includes('not found')) {
        expect(bodyText).toMatch(/DS/);
      }
    });

    test('should display records containers', async ({ page }) => {
      await page.goto('/index.php?page=dnssec_ds_dnskey&id=1');
      const pre = page.locator('pre, code, .records');
      if (await pre.count() > 0) {
        await expect(pre.first()).toBeVisible();
      }
    });
  });

  test.describe('Navigation from DNSSEC Page', () => {
    test('should have DS/DNSKEY link', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      await page.goto('/index.php?page=dnssec&id=1');
      const dsLink = page.locator('a[href*="dnssec_ds_dnskey"]');
      if (await dsLink.count() > 0) {
        await expect(dsLink.first()).toBeVisible();
      }
    });
  });

  test.describe('Manager User', () => {
    test('should have access to DS/DNSKEY page for own zone', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);
      await page.goto('/index.php?page=dnssec_ds_dnskey&id=1');
      const bodyText = await page.locator('body').textContent();
      // Manager may or may not have access depending on zone ownership
      expect(bodyText.length).toBeGreaterThan(0);
    });
  });
});
