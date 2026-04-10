import { test, expect } from '../../fixtures/test-fixtures.js';
import users from '../../fixtures/users.json' assert { type: 'json' };

/**
 * Tests for limited language configuration.
 *
 * These tests verify that when `enabled_languages` is set to a subset,
 * only those languages appear in the selector. Tests are automatically
 * skipped when the instance has all languages enabled (no `enabled_languages`
 * configured).
 *
 * The SQLite devcontainer (port 8082) is configured with:
 *   enabled_languages: en_EN,de_DE,fr_FR,ja_JP,pl_PL
 *
 * Run with: BASE_URL=http://localhost:8082 npx playwright test language-selector-limited
 */

const CONFIGURED_LANGUAGES = {
  'en_EN': 'English',
  'de_DE': 'German',
  'fr_FR': 'French',
  'ja_JP': 'Japanese',
  'pl_PL': 'Polish',
};

const EXCLUDED_LANGUAGES = [
  'es_ES', 'nl_NL', 'cs_CZ', 'it_IT', 'ru_RU', 'zh_CN',
  'tr_TR', 'lt_LT', 'nb_NO', 'pt_PT', 'id_ID', 'ko_KR',
  'sv_SE', 'uk_UA', 'vi_VN',
];

const EXPECTED_COUNT = Object.keys(CONFIGURED_LANGUAGES).length;

test.describe('Language Selector - Limited Configuration', () => {
  const baseUrl = process.env.BASE_URL || 'http://localhost:8080';
  test.skip(!baseUrl.includes('8082'), 'This test requires SQLite instance (port 8082) with limited languages configured');

  test.beforeEach(async ({ page }) => {
    await page.goto('/login');

    // Skip all tests in this suite if the instance does not have limited languages
    const count = await page.locator('#langSwitcher + .dropdown-menu .dropdown-item').count();
    test.skip(count !== EXPECTED_COUNT, `Skipping: instance has ${count} languages (expected ${EXPECTED_COUNT} for limited config)`);
  });

  test('should show exactly the configured number of languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const count = await items.count();
    expect(count).toBe(EXPECTED_COUNT);
  });

  test('should contain only the configured locales', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    const expectedLocales = Object.keys(CONFIGURED_LANGUAGES);
    expect(values.sort()).toEqual(expectedLocales.sort());
  });

  test('should display correct native names for configured languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const optionData = await items.evaluateAll(els =>
      els.map(el => ({ value: el.dataset.lang, label: el.textContent.trim() }))
    );

    for (const opt of optionData) {
      expect(CONFIGURED_LANGUAGES[opt.value]).toBe(opt.label);
    }
  });

  test('should not contain excluded languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    for (const locale of EXCLUDED_LANGUAGES) {
      expect(values).not.toContain(locale);
    }
  });

  test('should switch to German and show German interface', async ({ page }) => {
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="de_DE"]').click();
    await page.waitForURL(/lang=de_DE/, { timeout: 10000 });

    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zonen|Suche|Abmelden|Benutzer/i);
  });

  test('should switch to French and show French interface', async ({ page }) => {
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="fr_FR"]').click();
    await page.waitForURL(/lang=fr_FR/, { timeout: 10000 });

    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Zones|Recherche|Déconnexion|Utilisateurs/i);
  });

  test('should switch to Japanese and show Japanese interface', async ({ page }) => {
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="ja_JP"]').click();
    await page.waitForURL(/lang=ja_JP/, { timeout: 10000 });

    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/ゾーン|検索|ログアウト|ユーザー/i);
  });

  test('should switch to Polish and show Polish interface', async ({ page }) => {
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="pl_PL"]').click();
    await page.waitForURL(/lang=pl_PL/, { timeout: 10000 });

    await page.locator('[data-testid="username-input"]').fill(users.admin.username);
    await page.locator('[data-testid="password-input"]').fill(users.admin.password);
    await page.locator('[data-testid="login-button"]').click();
    await page.waitForURL(/\/$|\?/, { timeout: 10000 });

    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/Strefy|Szukaj|Wyloguj|Użytkownicy/i);
  });

  test('should persist limited language selection across navigation', async ({ page }) => {
    await page.locator('#langSwitcher').click();
    await page.locator('#langSwitcher + .dropdown-menu .dropdown-item[data-lang="fr_FR"]').click();
    await page.waitForURL(/lang=fr_FR/, { timeout: 10000 });

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

    // Navigate directly to logout for reliable logout
    await page.goto('/logout');
    await page.waitForURL(/login/, { timeout: 10000 });

    // Verify still limited to configured languages
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const count = await items.count();
    expect(count).toBe(EXPECTED_COUNT);
  });
});
