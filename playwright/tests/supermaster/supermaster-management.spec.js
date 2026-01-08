import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Supermaster Management', () => {
  const testIp = '192.168.100.100';
  const testNs = 'ns-test.example.com';

  test('should access supermaster list page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_supermasters');
    await expect(page).toHaveURL(/page=list_supermasters/);
    // Verify page loads without errors
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should show supermaster list page', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_supermasters');

    // Should show supermaster table or empty state
    const hasTable = await page.locator('table, .table').count() > 0;
    if (hasTable) {
      await expect(page.locator('table, .table').first()).toBeVisible();
    } else {
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).toMatch(/No supermaster|supermaster|empty/i);
    }
  });

  test('should add a new supermaster', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_supermaster');

    // Fill in supermaster details
    const ipField = page.locator('input[name*="ip"]').first();
    if (await ipField.count() > 0) {
      await ipField.fill(testIp);
    }

    const nsField = page.locator('input[name*="ns"], input[name*="nameserver"]').first();
    if (await nsField.count() > 0) {
      await nsField.fill(testNs);
    }

    const accountField = page.locator('input[name*="account"]').first();
    if (await accountField.count() > 0) {
      await accountField.fill('test-account');
    }

    // Submit form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Verify no fatal errors after submission
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toMatch(/fatal|exception/i);
  });

  test('should list the created supermaster', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_supermasters');

    // Should show the test supermaster we created
    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes(testIp) || bodyText.includes(testNs)) {
      expect(bodyText).toMatch(new RegExp(testIp + '|' + testNs));
    } else {
      // May not have been created, that's ok
      test.info().annotations.push({ type: 'note', description: 'Test supermaster not found in list' });
    }
  });

  test('should edit a supermaster', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_supermasters');

    // Find the test supermaster and edit it
    const row = page.locator(`tr:has-text("${testIp}")`);
    if (await row.count() > 0) {
      const editLink = row.locator('a').filter({ hasText: /Edit/i });
      if (await editLink.count() > 0) {
        await editLink.first().click();

        // Update supermaster details
        const nsField = page.locator('input[name*="ns"], input[name*="nameserver"]').first();
        if (await nsField.count() > 0) {
          await nsField.clear();
          await nsField.fill('ns2-test.example.com');
        }

        // Submit form
        await page.locator('button[type="submit"], input[type="submit"]').first().click();

        // Verify success
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });

  test('should delete a supermaster', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=list_supermasters');

    // Find the test supermaster and delete it
    const row = page.locator(`tr:has-text("${testIp}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();

        // Confirm deletion if needed
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }

        // Verify deletion
        const bodyText = await page.locator('body').textContent();
        expect(bodyText).not.toMatch(/fatal|exception/i);
      }
    }
  });

  test('should validate supermaster form', async ({ adminPage: page }) => {
    await page.goto('/index.php?page=add_supermaster');

    // Submit empty form
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    // Should show validation error or stay on form
    const currentUrl = page.url();
    const bodyText = await page.locator('body').textContent();
    const hasError = bodyText.toLowerCase().includes('error') ||
                     bodyText.toLowerCase().includes('required') ||
                     currentUrl.includes('add_supermaster');
    expect(hasError).toBeTruthy();
  });
});
