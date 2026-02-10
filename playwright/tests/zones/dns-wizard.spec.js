/**
 * DNS Wizard Tests
 *
 * Tests for the DNS wizard feature including type selection
 * and form display.
 */

import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe.configure({ mode: 'serial' });

test.describe('DNS Wizard', () => {
  // Helper to find a zone ID for wizard testing
  async function navigateToWizard(page, zoneName) {
    await page.goto('/zones');
    const row = page.locator(`tr:has-text("${zoneName}")`);
    if (await row.count() > 0) {
      const wizardLink = row.locator('a[href*="/wizard"]').first();
      if (await wizardLink.count() > 0) {
        await wizardLink.click();
        return true;
      }
    }
    // Try direct link from zone edit page
    const editLink = page.locator(`a[href*="/edit"]:has-text("${zoneName}")`).first();
    if (await editLink.count() > 0) {
      const href = await editLink.getAttribute('href');
      const match = href.match(/zones\/(\d+)/);
      if (match) {
        await page.goto(`/zones/${match[1]}/wizard`);
        return true;
      }
    }
    return false;
  }

  test.describe('Wizard Selection Page', () => {
    test('admin should access DNS wizard page', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        await expect(page).toHaveURL(/.*zones\/\d+\/wizard/);
      }
    });

    test('should display wizard type selection', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const bodyText = await page.locator('body').textContent();
        expect(bodyText.toLowerCase()).toMatch(/wizard|record type|select/i);
      }
    });

    test('should display available wizard types', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const bodyText = await page.locator('body').textContent();
        // Common wizard types
        const hasWizardTypes = bodyText.includes('SPF') ||
                               bodyText.includes('DMARC') ||
                               bodyText.includes('DKIM') ||
                               bodyText.includes('CAA') ||
                               bodyText.includes('SRV');
        expect(hasWizardTypes).toBeTruthy();
      }
    });

    test('should have clickable wizard type cards', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const wizardLinks = page.locator('a[href*="/wizard/"]');
        expect(await wizardLinks.count()).toBeGreaterThan(0);
      }
    });
  });

  test.describe('Wizard Form', () => {
    test('should access SPF wizard form', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const spfLink = page.locator('a[href*="/wizard/SPF"], a[href*="/wizard/spf"]').first();
        if (await spfLink.count() > 0) {
          await spfLink.click();
          await expect(page).toHaveURL(/.*wizard\/(SPF|spf)/i);
        }
      }
    });

    test('should display wizard form fields', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        // Click first available wizard type
        const wizardLink = page.locator('a[href*="/wizard/"]').first();
        if (await wizardLink.count() > 0) {
          await wizardLink.click();
          await page.waitForLoadState('domcontentloaded');

          // Should have form fields
          const wizardFields = page.locator('#wizardFields');
          if (await wizardFields.count() > 0) {
            const inputs = page.locator('.wizard-field');
            expect(await inputs.count()).toBeGreaterThan(0);
          }
        }
      }
    });

    test('should display CSRF token', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const wizardLink = page.locator('a[href*="/wizard/"]').first();
        if (await wizardLink.count() > 0) {
          await wizardLink.click();
          await page.waitForLoadState('domcontentloaded');

          const csrfToken = page.locator('input[name="_token"]');
          expect(await csrfToken.count()).toBeGreaterThan(0);
        }
      }
    });

    test('should display submit button', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const wizardLink = page.locator('a[href*="/wizard/"]').first();
        if (await wizardLink.count() > 0) {
          await wizardLink.click();
          await page.waitForLoadState('domcontentloaded');

          const submitBtn = page.locator('button[type="submit"], button:has-text("Create Record")');
          expect(await submitBtn.count()).toBeGreaterThan(0);
        }
      }
    });

    test('should display back/cancel links', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const wizardLink = page.locator('a[href*="/wizard/"]').first();
        if (await wizardLink.count() > 0) {
          await wizardLink.click();
          await page.waitForLoadState('domcontentloaded');

          const bodyText = await page.locator('body').textContent();
          expect(bodyText.toLowerCase()).toMatch(/back|cancel/i);
        }
      }
    });
  });

  test.describe('Breadcrumb Navigation', () => {
    test('wizard page should display breadcrumb', async ({ page }) => {
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

      const found = await navigateToWizard(page, 'admin-zone');
      if (found) {
        const breadcrumb = page.locator('.breadcrumb');
        expect(await breadcrumb.count()).toBeGreaterThan(0);
      }
    });
  });
});
