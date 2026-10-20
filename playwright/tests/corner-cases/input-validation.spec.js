import { test, expect } from '@playwright/test';
import { loginAndWaitForDashboard } from '../../helpers/auth.js';
import { domainToASCII, domainToUnicode } from 'node:url';
import { createZone, deleteZoneById, findZoneIdByName, uniqueName, uniqueZoneName, zoneExists } from '../../helpers/zones.js';
import users from '../../fixtures/users.json' with { type: 'json' };

// A refused submission re-renders the form with an error alert, so the
// page stays on the add form and the system message carries the error colour.
async function submitZoneName(page, zoneName) {
  await page.goto('/zones/add/master');
  await page.waitForLoadState('networkidle');

  await page.locator('[data-testid="zone-name-input"]').fill(zoneName);
  await page.locator('[data-testid="add-zone-button"]').click();
  await page.waitForLoadState('networkidle');
}

// Content of every stored record, whether the row renders an input or a textarea
async function storedContents(page, zoneId) {
  await page.goto(`/zones/${zoneId}/edit`);
  await page.waitForLoadState('networkidle');
  return page.locator('[name^="record["][name$="[content]"]').evaluateAll(els => els.map(e => e.value));
}

// Submit the add-record form the way a client that skips browser validation would
async function submitRecordUnchecked(page, zoneId, { type, name, content, ttl }) {
  await page.goto(`/zones/${zoneId}/edit`);
  await page.waitForLoadState('networkidle');

  await page.locator('#recordTypeSelectTop').selectOption(type);
  await page.locator('input[name="name"]').first().fill(name);
  await page.locator('#recordContentTop').fill(content);
  await page.locator('input[name="ttl"]').first().fill(ttl);
  await Promise.all([
    page.waitForNavigation(),
    page.locator('input[name="ttl"]').first().evaluate(el => {
      const commit = document.createElement('input');
      commit.type = 'hidden';
      commit.name = 'commit';
      commit.value = '1';
      el.form.appendChild(commit);
      el.form.submit();
    }),
  ]);
}

// Fill the add-record form at the top of the zone edit page and submit it
async function submitRecord(page, zoneId, { type, name, content, ttl }) {
  await page.goto(`/zones/${zoneId}/edit`);
  await page.waitForLoadState('networkidle');

  await page.locator('#recordTypeSelectTop').selectOption(type);
  await page.locator('input[name="name"]').first().fill(name);
  await page.locator('#recordContentTop').fill(content);
  if (ttl !== undefined) {
    await page.locator('input[name="ttl"]').first().fill(ttl);
  }
  await page.getByRole('button', { name: 'Add record' }).click();
  await page.waitForLoadState('networkidle');
}

test.describe('Input Validation Edge Cases', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
  });

  test.describe('Zone Name Validation', () => {
    test('should reject zone names with invalid characters', async ({ page }) => {
      await submitZoneName(page, 'invalid!domain.com');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page).toHaveURL(/\/zones\/add/);
      await expect(page.locator('[data-testid="system-message"]')).toBeVisible();
      expect(await zoneExists(page, 'invalid!domain.com')).toBe(false);
    });

    test('should reject zone names that are too long', async ({ page }) => {
      // Over 255 characters in total
      const longName = `${'a'.repeat(245)}.com`;
      await submitZoneName(page, longName);

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page).toHaveURL(/\/zones\/add/);
      await expect(page.locator('[data-testid="system-message"]')).toBeVisible();
      expect(await zoneExists(page, longName)).toBe(false);
    });

    test('should reject zone names with double dots', async ({ page }) => {
      await submitZoneName(page, 'invalid..domain.com');

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page).toHaveURL(/\/zones\/add/);
      await expect(page.locator('[data-testid="system-message"]')).toBeVisible();
      expect(await zoneExists(page, 'invalid..domain.com')).toBe(false);
    });

    test('should handle unicode IDN zone names correctly', async ({ page }, testInfo) => {
      const unicodeName = `${uniqueName('idn', testInfo)}-пример.example.com`;
      const idnZone = domainToASCII(unicodeName);
      expect(idnZone).toMatch(/xn--/);
      let zoneId = null;

      try {
        await submitZoneName(page, idnZone);
        await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

        // A punycode name is a valid zone name; the list shows it in Unicode form
        zoneId = await findZoneIdByName(page, domainToUnicode(idnZone));
        expect(zoneId, `zone ${idnZone} must be created`).toBeTruthy();
      } finally {
        zoneId ??= await findZoneIdByName(page, domainToUnicode(idnZone));
        if (zoneId) {
          await deleteZoneById(page, zoneId);
        }
      }
    });
  });

  test.describe('Record Validation', () => {
    test.describe.configure({ mode: 'serial' });

    const testZoneName = uniqueZoneName('valid');
    let zoneId = null;

    test.afterAll(async ({ browser }) => {
      const page = await browser.newPage();
      await loginAndWaitForDashboard(page, users.admin.username, users.admin.password);
      const id = zoneId ?? await findZoneIdByName(page, testZoneName);
      if (id) {
        await deleteZoneById(page, id);
      }
      await page.close();
    });

    test('should create test zone for record validation', async ({ page }) => {
      zoneId = await createZone(page, testZoneName);
      expect(zoneId, `zone ${testZoneName} must be created`).toBeTruthy();
    });

    test('should reject invalid IP addresses for A records', async ({ page }) => {
      await submitRecord(page, zoneId, { type: 'A', name: 'www', content: '256.256.256.256' });

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      // The refused record must not be stored
      expect(await storedContents(page, zoneId)).not.toContain('256.256.256.256');
    });

    test('should reject invalid hostnames for CNAME records', async ({ page }) => {
      await submitRecord(page, zoneId, { type: 'CNAME', name: 'mail', content: 'invalid..hostname.com' });

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      expect(await storedContents(page, zoneId)).not.toContain('invalid..hostname.com');
    });

    test('should refuse unquoted TXT content', async ({ page }) => {
      const longContent = 'a'.repeat(2000);
      await submitRecord(page, zoneId, { type: 'TXT', name: 'txt', content: longContent });

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      await expect(page.locator('#recordContentTop')).toHaveClass(/is-invalid/);
      await expect(page.locator('body')).toContainText(/enclosed in quotes/i);

      expect(await storedContents(page, zoneId)).not.toContain(longContent);
    });

    test('should handle invalid TTL values', async ({ page }) => {
      await submitRecordUnchecked(page, zoneId, { type: 'A', name: 'ttltest', content: '192.168.1.1', ttl: '-100' });

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);

      // The browser blocks min=0 itself, so post it directly: the server must refuse it
      await page.goto(`/zones/${zoneId}/edit`);
      expect(await storedContents(page, zoneId)).not.toContain('192.168.1.1');
      await expect(page.locator('input[name^="record["][name$="[ttl]"][value="-100"]')).toHaveCount(0);
    });

    test('should clean up test zone', async ({ page }) => {
      expect(zoneId, 'the test zone must have been created').toBeTruthy();
      expect(await deleteZoneById(page, zoneId), 'delete confirmation must be offered').toBe(true);

      await expect(page.locator('body')).not.toContainText(/fatal|exception/i);
      expect(await zoneExists(page, testZoneName)).toBe(false);
    });
  });

  test.describe('User Input Validation', () => {
    async function fillUserForm(page, { username, email, password }) {
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');

      await page.locator('#username').fill(username);
      await page.locator('#fullname').fill('Test User');
      await page.locator('#email').fill(email);
      await page.locator('#password').fill(password);
    }

    test('should reject invalid email addresses for users', async ({ page }, testInfo) => {
      await fillUserForm(page, {
        username: uniqueName('ivemail', testInfo),
        email: 'notanemail@',
        password: 'SecurePass123!@#',
      });
      // The browser blocks a malformed address before submit, so post the form directly
      await Promise.all([
        page.waitForNavigation(),
        page.evaluate(() => {
          const form = document.getElementById('addUserForm');
          const commit = document.createElement('input');
          commit.type = 'hidden';
          commit.name = 'commit';
          commit.value = '1';
          form.appendChild(commit);
          form.submit();
        }),
      ]);

      await expect(page).toHaveURL(/\/users\/add/);
      await expect(page.locator('[data-testid="system-message"]'))
        .toContainText(/fill in all required fields correctly/i);
    });

    test('should enforce password policy if configured', async ({ page }, testInfo) => {
      await page.goto('/users/add');
      await page.waitForLoadState('networkidle');
      // The rule list is only rendered while enable_password_rules is on
      test.skip(
        await page.getByText('Minimum length').count() === 0,
        'password policy is disabled on this instance (enable_password_rules)'
      );

      const username = uniqueName('ivpolicy', testInfo);
      await fillUserForm(page, { username, email: 'policy@example.com', password: 'weak' });
      await page.locator('button[name="commit"]').click();

      await expect(page).toHaveURL(/\/users\/add/);
      await expect(page.locator('[data-testid="system-message"].alert-danger')).toBeVisible();

      // The refused user must not exist
      // Usernames render as input values, so match the input rather than the text
      await page.goto(`/users?search=${username}`);
      await expect(page.locator(`tr:has(input[value="${username}"])`)).toHaveCount(0);
    });
  });
});
