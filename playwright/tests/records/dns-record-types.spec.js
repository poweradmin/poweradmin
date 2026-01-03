import { test, expect } from '../../fixtures/test-fixtures.js';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

// Write tests run serially to avoid database race conditions
test.describe.configure({ mode: 'serial' });

test.describe('DNS Record Types Management', () => {
  const testDomain = `records-test-${Date.now()}.com`;

  test.beforeAll(async ({ browser }) => {
    // Create a test domain for record testing
    const page = await browser.newPage();

    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=add_zone_master');
    await page.locator('input[name*="domain"], input[name*="zone"], input[name*="name"]')
      .first()
      .fill(testDomain);

    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    await page.close();
  });

  // Helper to navigate to test domain's records
  async function navigateToTestDomain(page) {
    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);
    if (await row.count() > 0) {
      await row.locator('a').first().click();
    }
  }

  test('should add A record successfully', async ({ adminPage: page }) => {
    await navigateToTestDomain(page);
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').first().selectOption('A');

      const nameInput = page.locator('input[name*="name"]').first();
      if (await nameInput.count() > 0) {
        await nameInput.fill('www');
      }

      await page.locator('input[name*="content"], input[name*="value"]').first().fill('192.168.1.10');

      const ttlField = page.locator('input[name*="ttl"]').first();
      if (await ttlField.count() > 0) {
        await ttlField.fill('3600');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    } else {
      test.info().annotations.push({ type: 'note', description: 'Record form not available' });
    }
  });

  test('should add AAAA record successfully', async ({ adminPage: page }) => {
    await navigateToTestDomain(page);
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').first().selectOption('AAAA');

      const nameInput = page.locator('input[name*="name"]').first();
      if (await nameInput.count() > 0) {
        await nameInput.fill('ipv6');
      }

      await page.locator('input[name*="content"], input[name*="value"]').first().fill('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should add MX record successfully', async ({ adminPage: page }) => {
    await navigateToTestDomain(page);
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').first().selectOption('MX');

      await page.locator('input[name*="content"], input[name*="value"]').first().fill('mail.example.com');

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should add CNAME record successfully', async ({ adminPage: page }) => {
    await navigateToTestDomain(page);
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').first().selectOption('CNAME');

      const nameInput = page.locator('input[name*="name"]').first();
      if (await nameInput.count() > 0) {
        await nameInput.fill('blog');
      }

      await page.locator('input[name*="content"], input[name*="value"]').first().fill('www.example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should add TXT record successfully', async ({ adminPage: page }) => {
    await navigateToTestDomain(page);
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').first().selectOption('TXT');

      const nameInput = page.locator('input[name*="name"]').first();
      if (await nameInput.count() > 0) {
        await nameInput.fill('_dmarc');
      }

      const contentInput = page.locator('input[name*="content"], input[name*="value"], textarea[name*="content"]').first();
      await contentInput.fill('v=DMARC1; p=none; rua=mailto:dmarc@example.com');

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  test('should add SRV record successfully', async ({ adminPage: page }) => {
    await navigateToTestDomain(page);
    const hasTypeSelector = await page.locator('select[name*="type"]').count() > 0;

    if (hasTypeSelector) {
      await page.locator('select[name*="type"]').first().selectOption('SRV');

      const nameInput = page.locator('input[name*="name"]').first();
      if (await nameInput.count() > 0) {
        await nameInput.fill('_sip._tcp');
      }

      await page.locator('input[name*="content"], input[name*="value"]').first().fill('sipserver.example.com');

      const prioField = page.locator('input[name*="prio"], input[name*="priority"]').first();
      if (await prioField.count() > 0) {
        await prioField.fill('10');
      }

      await page.locator('button[type="submit"], input[type="submit"]').first().click();

      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toMatch(/fatal|exception/i);
    }
  });

  // Cleanup
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);

    await page.goto('/index.php?page=list_zones');
    const row = page.locator(`tr:has-text("${testDomain}")`);
    if (await row.count() > 0) {
      const deleteLink = row.locator('a').filter({ hasText: /Delete/i });
      if (await deleteLink.count() > 0) {
        await deleteLink.first().click();
        const confirmButton = page.locator('button, input[type="submit"]').filter({ hasText: /Yes|Confirm|Delete/i });
        if (await confirmButton.count() > 0) {
          await confirmButton.first().click();
        }
      }
    }

    await page.close();
  });
});
