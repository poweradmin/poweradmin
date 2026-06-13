import { test, expect } from '../../fixtures/test-fixtures.js';

/**
 * Tests for the full language configuration (all default languages enabled).
 *
 * These tests verify the complete set of supported languages when
 * `enabled_languages` uses the default. Designed for the MySQL
 * devcontainer instance (port 8080).
 *
 * Run with: BASE_URL=http://localhost:8080 npx playwright test language-selector-full
 */

// Mirrors interface.enabled_languages in config/settings.defaults.php.
// Keep both in sync when adding or removing a locale.
const SUPPORTED_LOCALES = [
  'ar_SA', 'bg_BG', 'bs_BA', 'cs_CZ', 'da_DK', 'de_DE', 'el_GR', 'en_EN',
  'es_ES', 'et_EE', 'fa_IR', 'fi_FI', 'fr_FR', 'ga_IE', 'he_IL', 'hi_IN',
  'hr_HR', 'hu_HU', 'id_ID', 'it_IT', 'ja_JP', 'ko_KR', 'lt_LT', 'lv_LV',
  'ms_MY', 'nb_NO', 'nl_NL', 'pl_PL', 'pt_BR', 'pt_PT', 'ro_RO', 'ru_RU',
  'sk_SK', 'sl_SI', 'sq_AL', 'sr_RS', 'sv_SE', 'th_TH', 'tr_TR', 'uk_UA',
  'vi_VN', 'zh_CN', 'zh_TW',
];

test.describe('Language Selector - Full Configuration', () => {
  const baseUrl = process.env.BASE_URL || 'http://localhost:8080';
  test.skip(baseUrl.includes('8082'), 'This test requires a non-SQLite instance with all languages enabled');

  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
  });

  test('should have all supported languages', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const count = await items.count();
    expect(count).toBe(SUPPORTED_LOCALES.length);
  });

  test('should contain every supported language', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    for (const locale of SUPPORTED_LOCALES) {
      expect(values).toContain(locale);
    }
  });

  test('should have no extra unexpected locales', async ({ page }) => {
    const items = page.locator('#langSwitcher + .dropdown-menu .dropdown-item');
    const values = await items.evaluateAll(els => els.map(el => el.dataset.lang));

    for (const value of values) {
      expect(SUPPORTED_LOCALES).toContain(value);
    }
  });
});
