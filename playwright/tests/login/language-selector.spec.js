import { test, expect } from '../../fixtures/test-fixtures.js';
import { login } from '../../helpers/auth.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

test.describe('Language Selector', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should display language selector on login page', async ({ page }) => {
    const selector = page.locator('select[name="userlang"]');
    await expect(selector).toBeVisible();
  });

  test('should have English selected by default', async ({ page }) => {
    const selector = page.locator('select[name="userlang"]');
    const selectedValue = await selector.inputValue();
    expect(selectedValue).toBe('en_EN');
  });

  test('should contain all expected languages', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const count = await options.count();
    expect(count).toBeGreaterThanOrEqual(15);

    const values = await options.evaluateAll(opts => opts.map(o => o.value));
    const expectedLocales = [
      'en_EN', 'de_DE', 'fr_FR', 'es_ES', 'nl_NL', 'pl_PL',
      'cs_CZ', 'it_IT', 'ja_JP', 'ru_RU', 'zh_CN', 'tr_TR',
      'lt_LT', 'nb_NO', 'pt_PT',
    ];
    for (const locale of expectedLocales) {
      expect(values).toContain(locale);
    }
  });

  test('should contain newly added languages', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));

    const newLocales = ['id_ID', 'ko_KR', 'sv_SE', 'uk_UA', 'vi_VN'];
    for (const locale of newLocales) {
      expect(values).toContain(locale);
    }
  });

  test('should list languages in alphabetical order by name', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const labels = await options.evaluateAll(opts => opts.map(o => o.textContent.trim()));
    const sorted = [...labels].sort((a, b) => a.localeCompare(b));
    expect(labels).toEqual(sorted);
  });

  test('should change interface language to German after login', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('de_DE');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    // German UI should contain German words
    expect(bodyText).toMatch(/Zonen|Suche|Abmelden|Benutzer/i);
  });

  test('should change interface language to French after login', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('fr_FR');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|Recherche|Déconnexion|Utilisateurs/i);
  });

  test('should display English interface with default language', async ({ page }) => {
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|Search|Log out|Users/i);
  });

  test('should persist language selection across page navigation', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('de_DE');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    // Navigate to search page
    await page.goto('/search');
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Suche|Suchergebnis/i);
  });
});
