import { test, expect } from '../../fixtures/test-fixtures.js';

/**
 * Tests for full language configuration (all shipped languages enabled).
 *
 * These tests verify the complete set of supported languages when
 * `enabled_languages` uses the default (all languages). Designed for
 * the MySQL devcontainer instance (port 8080).
 *
 * Run with: BASE_URL=http://localhost:8080 npx playwright test language-selector-full
 */

// Must stay in sync with the enabled_languages default in config/settings.defaults.php.
const ALL_LOCALES = [
  'cs_CZ', 'de_DE', 'en_EN', 'es_ES', 'et_EE', 'fi_FI', 'fr_FR',
  'hr_HR', 'hu_HU', 'id_ID', 'it_IT', 'ja_JP', 'ko_KR', 'lt_LT',
  'lv_LV', 'nb_NO', 'nl_NL', 'pl_PL', 'pt_PT', 'ro_RO', 'ru_RU',
  'sk_SK', 'sr_RS', 'sv_SE', 'tr_TR', 'uk_UA', 'vi_VN', 'zh_CN',
];

test.describe('Language Selector - Full Configuration', () => {
  const baseUrl = process.env.BASE_URL || 'http://localhost:8080';
  test.skip(baseUrl.includes('8082'), 'This test requires a non-SQLite instance with all languages enabled');

  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should have every supported language', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    await expect(items).toHaveCount(ALL_LOCALES.length);
  });

  test('should contain all expected languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    for (const locale of ALL_LOCALES) {
      expect(values).toContain(locale);
    }
  });

  test('should have no extra unexpected locales', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    expect(values.length).toBeGreaterThan(0);
    for (const value of values) {
      expect(ALL_LOCALES).toContain(value);
    }
  });
});
