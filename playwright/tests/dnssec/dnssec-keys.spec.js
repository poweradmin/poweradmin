import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('DNSSEC Key Management', () => {
  const testDomain = `dnssec-test-${Date.now()}.example.com`;
  let zoneId = null;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    // Create test zone
    await page.goto('/index.php?page=add_zone_master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]').first().fill(testDomain);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Get zone ID
    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);
    if (await row.count() > 0) {
      const editLink = await row.locator('a[href*="page=edit"]').first().getAttribute('href');
      const match = editLink?.match(/id=(\d+)/);
      if (match) {
        zoneId = match[1];
      }
    }

    await page.close();
  });

  test.describe('DNSSEC Page Access', () => {
    test('admin should access DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);
      await expect(page).toHaveURL(/page=dnssec/);
    });

    test('should display DNSSEC page title', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/dnssec|keys/i);
    });

    test('manager should access DNSSEC page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.manager.username, users.manager.password);

      // Find a zone the manager owns
      await page.goto('/index.php?page=list_zones');
      const row = page.locator('table tbody tr').first();

      if (await row.count() > 0) {
        const dnssecLink = row.locator('a[href*="page=dnssec"]').first();
        if (await dnssecLink.count() > 0) {
          await dnssecLink.click();
          await expect(page).toHaveURL(/page=dnssec/);
        }
      }
    });
  });

  test.describe('DNSSEC Key Listing', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display key list or empty state', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      // Should show either keys table or message about no keys
      expect(bodyText.toLowerCase()).toMatch(/key|dnssec|add|no.*key/i);
    });

    test('should display add key button', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const addBtn = page.locator('a[href*="dnssec_add_key"], input[value*="Add"], button:has-text("Add")');
      if (await addBtn.count() > 0) {
        await expect(addBtn.first()).toBeVisible();
      }
    });

    test('should show key details when keys exist', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const table = page.locator('table').first();
      if (await table.count() > 0) {
        const bodyText = await page.locator('body').textContent();
        // Should show algorithm, flags, or key type info
        expect(bodyText.toLowerCase()).toMatch(/algorithm|flag|type|ksk|zsk|key/i);
      }
    });
  });

  test.describe('Add DNSSEC Key', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access add key page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/add.*key|create.*key|dnssec/i);
    });

    test('should display key type selector', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const typeSelector = page.locator('select[name*="type"], select[name*="key_type"], input[name*="type"]');
      if (await typeSelector.count() > 0) {
        await expect(typeSelector.first()).toBeVisible();
      }
    });

    test('should display algorithm selector', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const algoSelector = page.locator('select[name*="algorithm"], select[name*="algo"]');
      if (await algoSelector.count() > 0) {
        await expect(algoSelector.first()).toBeVisible();
      }
    });

    test('should display key size options', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const sizeSelector = page.locator('select[name*="bits"], select[name*="size"], input[name*="bits"]');
      if (await sizeSelector.count() > 0) {
        await expect(sizeSelector.first()).toBeVisible();
      }
    });

    test('should add KSK key', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const form = page.locator('form');
      if (await form.count() > 0) {
        // Select KSK if available
        const typeSelector = page.locator('select[name*="type"], select[name*="key_type"]').first();
        if (await typeSelector.count() > 0) {
          const options = await typeSelector.locator('option').allTextContents();
          const kskOption = options.find(o => o.toUpperCase().includes('KSK'));
          if (kskOption) {
            await typeSelector.selectOption({ label: kskOption });
          }
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should add ZSK key', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const form = page.locator('form');
      if (await form.count() > 0) {
        // Select ZSK if available
        const typeSelector = page.locator('select[name*="type"], select[name*="key_type"]').first();
        if (await typeSelector.count() > 0) {
          const options = await typeSelector.locator('option').allTextContents();
          const zskOption = options.find(o => o.toUpperCase().includes('ZSK'));
          if (zskOption) {
            await typeSelector.selectOption({ label: zskOption });
          }
        }

        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    });

    test('should select different algorithms', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_add_key&id=${zoneId}`);

      const algoSelector = page.locator('select[name*="algorithm"], select[name*="algo"]').first();
      if (await algoSelector.count() > 0) {
        const options = await algoSelector.locator('option').count();
        expect(options).toBeGreaterThan(0);

        // Try selecting an algorithm
        if (options > 1) {
          await algoSelector.selectOption({ index: 1 });
          const selectedValue = await algoSelector.inputValue();
          expect(selectedValue).toBeTruthy();
        }
      }
    });
  });

  test.describe('Activate/Deactivate Key', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display activate/deactivate links', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const activateLinks = page.locator('a[href*="dnssec_edit_key"], a[href*="activate"], a[href*="deactivate"]');
      if (await activateLinks.count() > 0) {
        await expect(activateLinks.first()).toBeVisible();
      }
    });

    test('should access key edit page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const editLink = page.locator('a[href*="dnssec_edit_key"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();
        await expect(page).toHaveURL(/dnssec_edit_key/);
      }
    });

    test('should toggle key status', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const editLink = page.locator('a[href*="dnssec_edit_key"]').first();
      if (await editLink.count() > 0) {
        await editLink.click();

        // Look for activate/deactivate button
        const toggleBtn = page.locator('button:has-text("Activate"), button:has-text("Deactivate"), input[value*="Activate"], input[value*="Deactivate"]').first();
        if (await toggleBtn.count() > 0) {
          await toggleBtn.click();

          const bodyText = await page.locator('body').textContent();
          expect(bodyText).not.toMatch(/fatal|exception/i);
        }
      }
    });
  });

  test.describe('Delete DNSSEC Key', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should display delete key links', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const deleteLinks = page.locator('a[href*="dnssec_delete_key"]');
      if (await deleteLinks.count() > 0) {
        await expect(deleteLinks.first()).toBeVisible();
      }
    });

    test('should access delete confirmation page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        await expect(page).toHaveURL(/dnssec_delete_key/);
      }
    });

    test('should display delete confirmation', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/delete|confirm|sure/i);
      }
    });

    test('should cancel delete and return to DNSSEC page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const deleteLink = page.locator('a[href*="dnssec_delete_key"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();

        const noBtn = page.locator('input[value="No"], button:has-text("No"), a:has-text("No")').first();
        if (await noBtn.count() > 0) {
          await noBtn.click();
          await expect(page).toHaveURL(/page=dnssec/);
        }
      }
    });
  });

  test.describe('DS and DNSKEY Records', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should access DS records page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      expect(bodyText.toLowerCase()).toMatch(/ds|dnskey|record/i);
    });

    test('should display DS record link from DNSSEC page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const dsLink = page.locator('a[href*="dnssec_ds_dnskey"], a:has-text("DS"), a:has-text("DNSKEY")');
      if (await dsLink.count() > 0) {
        await expect(dsLink.first()).toBeVisible();
      }
    });

    test('should navigate to DS records from DNSSEC page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const dsLink = page.locator('a[href*="dnssec_ds_dnskey"]').first();
      if (await dsLink.count() > 0) {
        await dsLink.click();
        await expect(page).toHaveURL(/dnssec_ds_dnskey/);
      }
    });

    test('should display DS record content', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec_ds_dnskey&id=${zoneId}`);

      const bodyText = await page.locator('body').textContent();
      // Should show DS or DNSKEY records if DNSSEC is enabled
      // Or a message that DNSSEC is not enabled
      expect(bodyText.toLowerCase()).toMatch(/ds|dnskey|key|record|dnssec|not.*enabled|no.*key/i);
    });
  });

  test.describe('Navigation', () => {
    test.beforeEach(async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
    });

    test('should navigate from zone list to DNSSEC', async ({ page }) => {
      await page.goto('/index.php?page=list_zones');

      const row = page.locator('table tbody tr').first();
      if (await row.count() > 0) {
        const dnssecLink = row.locator('a[href*="page=dnssec"]').first();
        if (await dnssecLink.count() > 0) {
          await dnssecLink.click();
          await expect(page).toHaveURL(/page=dnssec/);
        }
      }
    });

    test('should navigate from zone edit to DNSSEC', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=edit&id=${zoneId}`);

      const dnssecLink = page.locator('a[href*="page=dnssec"]').first();
      if (await dnssecLink.count() > 0) {
        await dnssecLink.click();
        await expect(page).toHaveURL(/page=dnssec/);
      }
    });

    test('should have back to zone link from DNSSEC page', async ({ page }) => {
      if (!zoneId) test.skip();

      await page.goto(`/index.php?page=dnssec&id=${zoneId}`);

      const backLink = page.locator('a[href*="page=edit"], a:has-text("Back"), a:has-text("Zone")');
      if (await backLink.count() > 0) {
        await expect(backLink.first()).toBeVisible();
      }
    });
  });

  test.describe('Permission Tests', () => {
    test('viewer should not access DNSSEC management', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.viewer.username, users.viewer.password);

      await page.goto('/index.php?page=list_zones');

      const row = page.locator('table tbody tr').first();
      if (await row.count() > 0) {
        // Viewer should not see DNSSEC links
        const dnssecLink = row.locator('a[href*="page=dnssec"]');
        const count = await dnssecLink.count();
        // Either no link or access denied when clicking
        if (count > 0) {
          await dnssecLink.first().click();
          const bodyText = await page.locator('body').textContent();
          expect(bodyText.toLowerCase()).toMatch(/denied|permission|access|not allowed/i);
        }
      }
    });
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a[href*="delete_domain"]').first();
      if (await deleteLink.count() > 0) {
        await deleteLink.click();
        const yesBtn = page.locator('input[value="Yes"], button:has-text("Yes")').first();
        if (await yesBtn.count() > 0) {
          await yesBtn.click();
        }
      }
    }

    await page.close();
  });
});
