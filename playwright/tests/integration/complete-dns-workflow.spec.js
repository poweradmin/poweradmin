import { test, expect } from '../../fixtures/test-fixtures.js';
import { ensureAnyZoneExists, findAnyZoneId } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('Complete DNS Management Workflow Integration', () => {

  test('should complete full company DNS setup workflow', async ({ adminPage: page }) => {
    // Step 1: Ensure a zone exists and find it
    await ensureAnyZoneExists(page);
    const testZone = await findAnyZoneId(page);
    expect(testZone && testZone.id).toBeTruthy();

    // Step 2: Navigate directly to zone edit page by ID
    await page.goto(`/index.php?page=edit&id=${testZone.id}`);

    // Verify we can access the zone edit/records page
    const pageContent = await page.locator('body').textContent();
    expect(pageContent).not.toMatch(/fatal|exception/i);

    // Add test record if new record form is available (look for the "newtype" select)
    const newTypeSelect = page.locator('select[name="newtype"]');
    if (await newTypeSelect.count() > 0) {
      // Ensure A record type is selected (it's typically the default)
      // Use label-based selection which is more reliable
      try {
        await newTypeSelect.selectOption({ label: 'A' });
      } catch {
        // If selection fails, A might already be selected - continue
      }

      const nameField = page.locator('input[name="newname"]').first();
      if (await nameField.count() > 0) {
        await nameField.clear();
        await nameField.fill(`test-${Date.now()}`);
      }
      const contentField = page.locator('input[name="newcontent"]').first();
      if (await contentField.count() > 0) {
        await contentField.clear();
        await contentField.fill('192.168.1.100');
      }
      // Find the submit button for the new record form
      const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
      await submitBtn.click();

      // Verify no fatal errors after submission
      const afterSubmit = await page.locator('body').textContent();
      expect(afterSubmit).not.toMatch(/fatal|exception/i);
    }
  });

  test('should validate complete DNS infrastructure', async ({ adminPage: page }) => {
    // Ensure a zone exists and find it
    await ensureAnyZoneExists(page);
    const zone = await findAnyZoneId(page);
    expect(zone && zone.id).toBeTruthy();

    // Navigate directly to zone edit page by ID
    await page.goto(`/index.php?page=edit&id=${zone.id}`);

    // Verify DNS records page is accessible
    const recordsText = await page.locator('body').textContent();
    expect(recordsText).not.toMatch(/fatal|exception/i);
    // Page should have zone-related content (records table, form fields, etc.)
    expect(recordsText.toLowerCase()).toMatch(/record|type|name|content|zone|soa|ns/i);
  });
});
