import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Tests for limited language configuration.
 *
 * These tests verify that when `enabled_languages` is set to a subset,
 * only those languages appear in the selector. Designed for the SQLite
 * devcontainer instance (port 8082) with: en_EN,de_DE,fr_FR,ja_JP,pl_PL
 *
 * Run with: BASE_URL=http://localhost:8082 npx playwright test language-selector-limited
 */

const CONFIGURED_LANGUAGES = {
  'en_EN': 'English',
  'de_DE': 'Deutsch',
  'fr_FR': 'Français',
  'ja_JP': '日本語',
  'pl_PL': 'Polski',
};

const EXCLUDED_LANGUAGES = [
  'es_ES', 'nl_NL', 'cs_CZ', 'it_IT', 'ru_RU', 'zh_CN',
  'tr_TR', 'lt_LT', 'nb_NO', 'pt_PT', 'id_ID', 'ko_KR',
  'sv_SE', 'uk_UA', 'vi_VN',
];

test.describe('Language Selector - Limited Configuration', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should show exactly the configured number of languages', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const count = await options.count();
    expect(count).toBe(Object.keys(CONFIGURED_LANGUAGES).length);
  });

  test('should contain only the configured locales', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));

    const expectedLocales = Object.keys(CONFIGURED_LANGUAGES);
    expect(values.sort()).toEqual(expectedLocales.sort());
  });

  test('should display correct native names for configured languages', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const optionData = await options.evaluateAll(opts =>
      opts.map(o => ({ value: o.value, label: o.textContent.trim() }))
    );

    for (const opt of optionData) {
      expect(CONFIGURED_LANGUAGES[opt.value]).toBe(opt.label);
    }
  });

  test('should not contain excluded languages', async ({ page }) => {
    const options = page.locator('select[name="userlang"] option');
    const values = await options.evaluateAll(opts => opts.map(o => o.value));

    for (const locale of EXCLUDED_LANGUAGES) {
      expect(values).not.toContain(locale);
    }
  });

  test('should switch to German and show German interface', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('de_DE');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zonen|Suche|Abmelden|Benutzer/i);
  });

  test('should switch to French and show French interface', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('fr_FR');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|Recherche|Déconnexion|Utilisateurs/i);
  });

  test('should switch to Japanese and show Japanese interface', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('ja_JP');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/ゾーン|検索|ログアウト|ユーザー/i);
  });

  test('should switch to Polish and show Polish interface', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('pl_PL');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Strefy|Szukaj|Wyloguj|Użytkownicy/i);
  });

  test('should persist limited language selection across navigation', async ({ page }) => {
    await page.locator('select[name="userlang"]').selectOption('fr_FR');
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    // Navigate to search page
    await page.goto('/search');
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Recherche|Rechercher/i);
  });

  test('should show limited languages on login page after logout', async ({ page }) => {
    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    // Log out
    await page.locator('a[href*="logout"]').first().click();
    await page.waitForURL(/login/, { timeout: 10000 });

    // Verify still limited to configured languages
    const options = page.locator('select[name="userlang"] option');
    const count = await options.count();
    expect(count).toBe(Object.keys(CONFIGURED_LANGUAGES).length);
  });
});
